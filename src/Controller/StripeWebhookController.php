<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
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
    protected LoggerChannelInterface $logger;

    public function __construct(ConfigFactoryInterface $config_factory, Connection $database, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->configFactory = $config_factory;
        $this->database = $database;
        $this->logger = $logger_factory->get('esn_cyprus_pass_validation');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configFactory,
            $database,
            $loggerFactory
        );
    }

    public function handleWebhook(Request $request): Response
    {
        $payload = $request->getContent();
        $sig_header = $request->headers->get('Stripe-Signature');

        $module_config = $this->configFactory->get('esn_cyprus_pass_validation.settings');
        $stripeSecretKey = $module_config->get('stripe_secret_key');
        $stripeWebhookSecret = $module_config->get('stripe_webhook_secret');
        if (empty($stripeSecretKey) || empty($stripeWebhookSecret)) {
            $this->logger->error('Stripe Secret Key and/or Stripe Webhook Key not set in the module configuration.');
            return new Response('Webhook error', 400);
        }
        Stripe::setApiKey($stripeSecretKey);

        try {
            $event = Webhook::constructEvent($payload, $sig_header, $stripeWebhookSecret);

            if ($event->type === 'checkout.session.completed') {
                $session = $event->data->object;
                $submission_id = $session->metadata->webform_submission_id ?? NULL;
                $link_id = $session->metadata->payment_link ?? NULL;

                if ($submission_id) {
                    $submission = WebformSubmission::load($submission_id);
                    if ($submission) {
                        // Mark submission as Paid.
                        $submission->setElementData('approval_status', 'Paid');
                        $submission->setElementData('date_paid', (new DrupalDateTime())->format('Y-m-d H:i:s'));

                        $this->assign_esncard_number($submission);

                        try {
                            PaymentLink::update(
                                $link_id,
                                ['active' => false]
                            );
                        } catch (Exception $e) {
                            $this->logger->error(
                                'Submission @id processed, but failed to deactivate Stripe Payment Link @link_id: @message',
                                [
                                    '@id' => $submission_id,
                                    '@link_id' => $link_id,
                                    '@message' => $e->getMessage()
                                ]
                            );
                        }

                        $this->logger->notice('Submission @id marked as Paid and assigned ESNcard number.', ['@id' => $submission_id]);
                    } else {
                        $this->logger->warning('Submission ID @id from Stripe webhook not found.', ['@id' => $submission_id]);
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
    private function assign_esncard_number(WebformSubmissionInterface $submission): void
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
            $next_number = $query->execute()->fetchField();

            if ($next_number) {
                $this->database->update('esncard_numbers')
                    ->fields(['assigned' => 1])
                    ->condition('number', $next_number)
                    ->execute();

                $submission->setElementData('esncard_number', $next_number);
                $submission->save();

                $this->logger->notice('Assigned ESNcard number @num to submission @id.', [
                    '@num' => $next_number,
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