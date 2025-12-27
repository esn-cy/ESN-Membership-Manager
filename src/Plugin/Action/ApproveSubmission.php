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
     */
    public function execute($id = null): void
    {
        if (empty($id)) {
            return;
        }

        try {
            $data = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('id', $id)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Failed to load application @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            return;
        }

        if (!$data) {
            return;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $now = (new DrupalDateTime())->format('Y-m-d H:i:s');

        if (empty($data['esncard'])) {
            try {
                $this->database->update('esn_membership_manager_applications')
                    ->fields([
                        'approval_status' => 'Approved',
                        'date_approved' => $now,
                    ])
                    ->condition('id', $id)
                    ->execute();

                if (!empty($moduleConfig->get('google_issuer_id'))) {
                    try {
                        $googleWalletLink = $this->googleService->getFreePassObject($data);
                    } catch (\Google\Service\Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                    } catch (Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                    }
                }

                $emailParams = [
                    'name' => $data['name'],
                    'token' => $data['pass_token'],
                    'google_wallet_link' => $googleWalletLink ?? '',
                ];
                $this->emailManager->sendEmail($data['email'], 'pass_approval', $emailParams);

                $this->logger->notice('Approved submission @id (no ESNcard requested).', ['@id' => $id]);
                return;
            } catch (Exception $e) {
                $this->logger->error('Updating Application @id failed: @message', ['@id' => $id, '@message' => $e->getMessage()]);
                return;
            }
        }

        try {
            $query = $this->database->select('esn_membership_manager_cards');
            $query->addExpression('COUNT(*)', 'count');
            $query->condition('assigned', 0);
            $count = $query->execute()->fetchField();
        } catch (Exception $e) {
            $this->logger->error('Querying number of available ESNcards failed: @message.', ['@message' => $e->getMessage()]);
            return;
        }

        if ($count == 0) {
            $this->logger->warning(
                'Submission @id requested ESNcard but none are available.',
                ['@id' => $id]
            );
            return;
        }

        $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
        if (empty($stripeSecretKey)) {
            $this->logger->error('Stripe Secret Key not set in the module configuration.');
            return;
        }
        Stripe::setApiKey($stripeSecretKey);

        try {
            $paymentLink = $this->createStripePaymentLink($id);
        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe API error for submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            return;
        }

        if (!$paymentLink) {
            $this->logger->error('Failed to create payment link for submission @id.', ['@id' => $id]);
            return;
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => 'Approved',
                    'date_approved' => $now,
                    'payment_link' => $paymentLink
                ])
                ->condition('id', $id)
                ->execute();

            $emailParams = [
                'name' => $data['name'],
                'token' => $data['pass_token'],
                'payment_link' => $paymentLink,
                'google_wallet_link' => ''
            ];

            if (!empty($data['pass'])) {
                if (!empty($moduleConfig->get('google_issuer_id'))) {
                    try {
                        $emailParams['google_wallet_link'] = $this->googleService->getFreePassObject($data);
                    } catch (\Google\Service\Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                    } catch (Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                    }
                }
                $this->emailManager->sendEmail($data['email'], 'both_approval', $emailParams);
            } else {
                $this->emailManager->sendEmail($data['email'], 'card_approval', $emailParams);
            }

            $this->logger->notice('Approved submission @id and created payment link.', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Failed to save submission @id after creating payment link: @message.', [
                '@id' => $id,
                '@message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a Stripe payment link for the given submission.
     *
     * @param int $id
     *   The application ID.
     *
     * @return string|null
     *   The payment link URL or null on failure.
     * @throws ApiErrorException
     */
    protected function createStripePaymentLink(int $id): ?string
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

        return $paymentLink->url ?? null;
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