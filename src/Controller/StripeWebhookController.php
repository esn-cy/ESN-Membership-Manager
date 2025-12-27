<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\WeeztixApiService;
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
    protected EmailManager $emailManager;

    protected WeeztixApiService $weeztixService;
    protected GoogleService $googleService;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        LockBackendInterface          $lock,
        LoggerChannelFactoryInterface $loggerFactory,
        EmailManager      $emailManager,
        WeeztixApiService $weeztixService,
        GoogleService     $googleService
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->lock = $lock;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->emailManager = $emailManager;
        $this->weeztixService = $weeztixService;
        $this->googleService = $googleService;
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

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var WeeztixApiService $weeztixService */
        $weeztixService = $container->get('esn_membership_manager.weeztix_api_service');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        return new static(
            $configFactory,
            $database,
            $lock,
            $loggerFactory,
            $emailManager,
            $weeztixService,
            $googleService
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
        } catch (Exception $e) {
            $this->logger->error('Unable to construct webhook event: @message', ['@message' => $e->getMessage()]);
            return new Response('Webhook failed', 400);
        }

        if ($event->type != 'checkout.session.completed') {
            return new Response('Webhook ignored', 200);
        }

        $session = $event->data->object;
        $applicationID = $session->metadata->application_id ?? NULL;
        $linkID = $session->payment_link ?? NULL;

        if (!$applicationID) {
            $this->logger->warning('No application_id metadata in Stripe session.');
            return new Response('Webhook ignored', 200);
        }

        if (!$this->lock->acquire('process_application_' . $applicationID)) {
            $this->logger->warning('Could not acquire lock for application @id. Another process may be running.', ['@id' => $applicationID]);
            return new Response('Webhook handled with lock conflict', 200);
        }

        try {
            $application = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('id', $applicationID)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Failed to load application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            return new Response('Webhook failed', 500);
        }

        if (!$application) {
            $this->logger->warning('Application @id was not found.', ['@id' => $applicationID]);
            $this->lock->release('process_application_' . $applicationID);
            return new Response('Webhook failed', 400);
        }

        if ($application['approval_status'] == 'Paid' && !empty($application['esncard_number'])) {
            $this->logger->warning(
                'Application @id was already paid. Duplicate payment event detected @linkID: @message',
                [
                    '@id' => $applicationID,
                    '@linkID' => $linkID,
                ]
            );
            $this->lock->release('process_application_' . $applicationID);
            return new Response('Webhook handled with warning', 200);
        }

        try {
            $esnCard = $this->assignESNcardNumber($applicationID);
        } catch (Exception $e) {
            $this->logger->error('Failed to assign an ESNcard number to application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            return new Response('Webhook failed', 500);
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => 'Paid',
                    'date_paid' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
                    'esncard_number' => $esnCard
                ])
                ->condition('id', $applicationID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Failed to update application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            return new Response('Webhook failed', 500);
        }

        try {
            PaymentLink::update(
                $linkID,
                ['active' => false]
            );
        } catch (Exception $e) {
            $this->logger->error(
                'Application @id processed, but failed to deactivate Stripe Payment Link @linkID: @message',
                [
                    '@id' => $applicationID,
                    '@linkID' => $linkID,
                    '@message' => $e->getMessage()
                ]
            );
        }

        $this->logger->notice('Application @id marked as Paid and assigned ESNcard number.', ['@id' => $applicationID]);

        if (!empty($moduleConfig->get('weeztix_client_id'))) {
            $this->weeztixService->addCoupon($esnCard, ['applies_to_count' => 1]);
        }

        if (!empty($moduleConfig->get('google_spreadsheet_id'))) {
            $this->googleService->appendRow(
                [
                    'date' => str_replace('-', '/', date('d-m-y')),
                    'name' => $application['name'] . ' ' . $application['surname'],
                    'card_number' => $esnCard,
                    'pos' => 'ESN Membership Manager',
                    'host' => $application['host_institution'],
                    'nationality' => $application['nationality'],
                    'mop' => 'Stripe',
                    'amount' => 16,
                ]
            );
        }

        if (!empty($moduleConfig->get('google_issuer_id'))) {
            try {
                $googleWalletLink = $this->googleService->getESNcardObject($application);
            } catch (\Google\Service\Exception $e) {
                $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
            } catch (Exception $e) {
                $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
            }
        }

        $emailParams = [
            'name' => $application['name'],
            'esncard_number' => $esnCard,
            'google_wallet_link' => $googleWalletLink ?? '',
        ];
        $this->emailManager->sendEmail($application['email'], 'card_assignment', $emailParams);

        $this->lock->release('process_application_' . $applicationID);
        return new Response('Webhook handled', 200);
    }


    /**
     * Assigns the next available ESNcard number to a submission.
     * @throws Exception
     */
    private function assignESNcardNumber($applicationID): string
    {
        $transaction = $this->database->startTransaction();

        try {
            $query = $this->database->select('esn_membership_manager_cards', 'e')
                ->fields('e', ['number'])
                ->condition('assigned', 0)
                ->orderBy('id')
                ->range(0, 1)
                ->forUpdate();

            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $nextNumber = $query->execute()->fetchField();

            if ($nextNumber) {
                $this->database->update('esn_membership_manager_cards')
                    ->fields(['assigned' => 1])
                    ->condition('number', $nextNumber)
                    ->execute();

                $this->logger->notice('Assigned ESNcard number @num to application @id.', [
                    '@num' => $nextNumber,
                    '@id' => $applicationID,
                ]);
                return $nextNumber;
            } else {
                $this->logger->warning('No available ESNcard numbers left to assign.');
                throw new Exception('Failed to assign ESNcard number: No available ESNcard numbers left to assign.');
            }
        } catch (Exception $e) {
            $transaction->rollBack();
            $this->logger->error('Failed to assign ESNcard number: @message', ['@message' => $e->getMessage()]);
            throw $e;
        }
    }
}