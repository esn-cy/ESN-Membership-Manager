<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\GoogleService;
use Exception;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentLink;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approves an application and creates a Stripe payment link.
 *
 * @Action(
 *   id = "esn_membership_manager_approve",
 *   label = @Translation("Approve Submissions"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class ApproveSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected ConfigFactoryInterface $configFactory;
    protected Connection $database;
    protected EmailManager $emailManager;
    protected GoogleService $googleService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        EmailManager                  $emailManager,
        GoogleService                 $googleService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->googleService = $googleService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
            $emailManager,
            $googleService,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute($id = null): void
    {
        if (empty($id)) {
            return;
        }

        try {
            $application = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('id', $id)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Failed to load application @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            throw new Exception('Failed to load application');
        }

        if (empty($application)) {
            $this->logger->warning('Application @id was not found', ['@id' => $id]);
            throw new Exception('Application not found');
        }

        if ($application['approval_status'] != 'Pending') {
            $this->logger->warning('Application @id cannot be marked as delivered because its current status is @status.', ['@id' => $id, '@status' => $application['status']]);
            throw new Exception('This status cannot be applied');
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $now = (new DrupalDateTime())->format('Y-m-d H:i:s');

        if (empty($application['esncard'])) {
            $token = strtoupper(md5(uniqid(rand(), true)));

            try {
                $this->database->update('esn_membership_manager_applications')
                    ->fields([
                        'pass_token' => $token,
                        'approval_status' => 'Approved',
                        'date_approved' => $now,
                    ])
                    ->condition('id', $id)
                    ->execute();

                $application['pass_token'] = $token;
                $application['approval_status'] = 'Approved';
                $application['date_approved'] = $now;

                if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                    try {
                        $googleWalletLink = $this->googleService->getFreePassObject($application);
                    } catch (\Google\Service\Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                    } catch (Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                    }
                }

                $emailParams = [
                    'name' => $application['name'],
                    'token' => $application['pass_token'],
                    'google_wallet_link' => $googleWalletLink ?? '',
                ];
                $this->emailManager->sendEmail($application['email'], 'pass_approval', $emailParams);

                $this->logger->notice('Approved submission @id (no ESNcard requested).', ['@id' => $id]);
                return;
            } catch (Exception $e) {
                $this->logger->error('Updating Application @id failed: @message', ['@id' => $id, '@message' => $e->getMessage()]);
                throw new Exception('Failed to update application');
            }
        }

        try {
            $query = $this->database->select('esn_membership_manager_cards');
            $query->addExpression('COUNT(*)', 'count');
            $query->condition('assigned', 0);
            $count = $query->execute()->fetchField();
        } catch (Exception $e) {
            $this->logger->error('Querying number of available ESNcards failed: @message.', ['@message' => $e->getMessage()]);
            throw new Exception('Failed to check ESNcard availability');
        }

        if ($count == 0) {
            $this->logger->warning(
                'Submission @id requested ESNcard but none are available.',
                ['@id' => $id]
            );
            throw new Exception('No available ESNcards');
        }

        $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
        if (empty($stripeSecretKey)) {
            $this->logger->error('Stripe Secret Key not set in the module configuration.');
            throw new Exception('Stripe Secret Key not set');
        }
        Stripe::setApiKey($stripeSecretKey);

        try {
            $paymentLink = $this->createStripePaymentLink($id);
        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe API error for submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            throw new Exception('Stripe API Error');
        }

        if (!$paymentLink) {
            $this->logger->error('Failed to create payment link for submission @id.', ['@id' => $id]);
            throw new Exception('Failed to create payment link');
        }

        try {
            if ($application['pass'])
                $token = strtoupper(md5(uniqid(rand(), true)));

            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'pass_token' => $token ?? NULL,
                    'approval_status' => 'Approved',
                    'date_approved' => $now,
                    'payment_link' => $paymentLink->url,
                    'payment_link_id' => $paymentLink->id,
                ])
                ->condition('id', $id)
                ->execute();

            $application['pass_token'] = $token ?? NULL;
            $application['approval_status'] = 'Approved';
            $application['date_approved'] = $now;
            $application['payment_link'] = $paymentLink->url;

            $emailParams = [
                'name' => $application['name'],
                'token' => $application['pass_token'],
                'payment_link' => $application['payment_link'],
                'google_wallet_link' => ''
            ];

            if (!empty($application['pass'])) {
                if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                    try {
                        $emailParams['google_wallet_link'] = $this->googleService->getFreePassObject($application);
                    } catch (\Google\Service\Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                    } catch (Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                    }
                }
                $this->emailManager->sendEmail($application['email'], 'both_approval', $emailParams);
            } else {
                $this->emailManager->sendEmail($application['email'], 'card_approval', $emailParams);
            }

            $this->logger->notice('Approved submission @id and created payment link.', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save submission @id after creating payment link: @message.', [
                '@id' => $id,
                '@message' => $e->getMessage()
            ]);
            throw new Exception('Failed to complete approval process');
        }
    }

    /**
     * Create a Stripe payment link for the given submission.
     *
     * @param int $id
     *   The application ID.
     *
     * @return PaymentLink|null
     *   The payment link URL or null on failure.
     * @throws ApiErrorException
     */
    protected function createStripePaymentLink(int $id): ?PaymentLink
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $esnCardPriceID = $moduleConfig->get('stripe_price_id_esncard');
        $processingFeePriceID = $moduleConfig->get('stripe_price_id_processing');

        if (empty($esnCardPriceID) || empty($processingFeePriceID)) {
            $this->logger->error('Stripe Price IDs for ESNcard or Processing Fee are not configured.');
            return null;
        }

        $paymentLink = PaymentLink::create([
            'line_items' => [
                ['price' => $esnCardPriceID, 'quantity' => 1,],
                ['price' => $processingFeePriceID, 'quantity' => 1,]
            ],
            'metadata' => ['application_id' => (string)$id]
        ]);

        return $paymentLink ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'approve submission');
        return $return_as_object ? $access : $access->isAllowed();
    }
}