<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;
use Exception;
use Stripe\PaymentLink;
use Stripe\Stripe;
use Stripe\Webhook;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends ControllerBase
{
    protected $configFactory;
    protected Connection $database;
    protected LockBackendInterface $lock;
    protected LoggerChannelInterface $logger;

    public function __construct(ConfigFactoryInterface $configFactory, Connection $database, LockBackendInterface $lock, LoggerChannelFactoryInterface $loggerFactory)
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->lock = $lock;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LockBackendInterface $lock */
        $lock = $container->get('lock');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configFactory,
            $database,
            $lock,
            $loggerFactory
        );
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $signatureHeader = $request->headers->get('Stripe-Signature');

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
        $stripeWebhookSecret = $moduleConfig->get('stripe_webhook_secret');
        if (empty($stripeSecretKey) || empty($stripeWebhookSecret)) {
            $this->logger->error('Stripe Secret Key and/or Stripe Webhook Key not set in the module configuration.');
            return new Response('Webhook error', 400);
        }
        Stripe::setApiKey($stripeSecretKey);

        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $stripeWebhookSecret);

            if ($event->type === 'checkout.session.completed') {
                $session = $event->data->object;
                $submissionID = $session->metadata->webform_submission_id ?? NULL;
                $linkID = $session->payment_link ?? NULL;

                if ($submissionID) {
                    if (!$this->lock->acquire('process_submission_' . $submissionID)) {
                        $this->logger->warning('Could not acquire lock for submission @id. Another process may be running.', ['@id' => $submissionID]);
                        return new Response('Webhook handled with lock conflict', 200);
                    }

                    try {
                        $submission = WebformSubmission::load($submissionID);
                        if ($submission) {
                            $submissionData = $submission->getData();
                            if ($submissionData['approval_status'] == 'Paid' && !empty($submissionData['esncard_number'])) {
                                $this->logger->warning(
                                    'Submission @id was already paid. Duplicate payment event detected @linkID: @message',
                                    [
                                        '@id' => $submissionID,
                                        '@linkID' => $linkID,
                                    ]
                                );
                                return new Response('Webhook handled with warning', 200);
                            }

                            // Mark submission as Paid.
                            $submission->setElementData('approval_status', 'Paid');
                            $submission->setElementData('date_paid', (new DrupalDateTime())->format('Y-m-d H:i:s'));

                            $this->assignESNcardNumber($submission);

                            try {
                                PaymentLink::update(
                                    $linkID,
                                    ['active' => false]
                                );
                            } catch (Exception $e) {
                                $this->logger->error(
                                    'Submission @id processed, but failed to deactivate Stripe Payment Link @linkID: @message',
                                    [
                                        '@id' => $submissionID,
                                        '@linkID' => $linkID,
                                        '@message' => $e->getMessage()
                                    ]
                                );
                            }

                            $this->logger->notice('Submission @id marked as Paid and assigned ESNcard number.', ['@id' => $submissionID]);
                        } else {
                            $this->logger->warning('Submission ID @id from Stripe webhook not found.', ['@id' => $submissionID]);
                        }
                    } finally {
                        $this->lock->release('process_submission_' . $submissionID);
                    }
                } else {
                    $this->logger->warning('No webform_submission_id metadata in Stripe session.');
                }
            }

            return new Response('Webhook handled', 200);
        } catch (Exception $e) {
            $this->logger->error('Stripe webhook error: @message', ['@message' => $e->getMessage()]);
            return new Response('Webhook error', 400);
        }
    }

    /**
     * Assigns the next available ESNcard number to a submission.
     * @throws EntityStorageException
     */
    private function assignESNcardNumber(WebformSubmissionInterface $submission): void
    {
        $transaction = $this->database->startTransaction();

        try {
            $query = $this->database->select('esncard_numbers', 'e')
                ->fields('e', ['number'])
                ->condition('assigned', 0)
                ->orderBy('sequence')
                ->range(0, 1)
                ->forUpdate();

            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $nextNumber = $query->execute()->fetchField();

            if ($nextNumber) {
                $this->database->update('esncard_numbers')
                    ->fields(['assigned' => 1])
                    ->condition('number', $nextNumber)
                    ->execute();

                $submission->setElementData('esncard_number', $nextNumber);
                $submission->save();

                $this->logger->notice('Assigned ESNcard number @num to submission @id.', [
                    '@num' => $nextNumber,
                    '@id' => $submission->id(),
                ]);
            } else {
                $this->logger->warning('No available ESNcard numbers left to assign.');
            }
        } catch (Exception $e) {
            $transaction->rollBack();
            $this->logger->error('Failed to assign ESNcard number: @message', ['@message' => $e->getMessage()]);
            throw $e;
        }
    }
}