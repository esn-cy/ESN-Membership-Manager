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
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Stripe\Exception\ApiErrorException;
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
    protected StripeService $stripeService;
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        StripeService $stripeService,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->stripeService = $stripeService;
        $this->emailManager = $emailManager;
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

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
            $stripeService,
            $emailManager,
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
            $this->logger->warning('Application @id cannot be marked as delivered because its current status is @status.', ['@id' => $id, '@status' => $application['approval_status']]);
            throw new Exception('This status cannot be applied');
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $now = (new DrupalDateTime())->format('Y-m-d H:i:s');

        $token = strtoupper(md5(uniqid(rand(), true)));

        $updateFields = [
            'pass_token' => $token,
            'approval_status' => 'Approved',
            'date_approved' => $now,
        ];

        $emailFields = [
            'name' => $application['name'],
            'pass_token' => $token,
        ];

        if ($application['esncard']) {
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

            try {
                $isESNer = $application['mobility_status'] == 'ESN Volunteer' || $application['mobility_status'] == 'ESN Alumnus';

                $paymentLink = $this->stripeService->createPaymentLink($id, $isESNer);
            } catch (ApiErrorException $e) {
                $this->logger->error('Stripe API error for submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
                throw new Exception('Stripe API Error');
            }

            if (!$paymentLink) {
                $this->logger->error('Failed to create payment link for submission @id.', ['@id' => $id]);
                throw new Exception('Failed to create payment link');
            }

            $updateFields += [
                'payment_link' => $paymentLink->url,
                'payment_link_id' => $paymentLink->id,
            ];

            $emailFields += ['payment_link' => $paymentLink->url];
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields($updateFields)
                ->condition('id', $id)
                ->execute();

            if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                $googleWalletLink = Url::fromRoute(
                    'esn_membership_manager.add_to_google_wallet',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            if ($moduleConfig->get('switch_apple_wallet') ?? FALSE) {
                $appleWalletLink = Url::fromRoute(
                    'esn_membership_manager.download_apple_pass',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            $emailFields += [
                'google_wallet_link' => $googleWalletLink ?? '',
                'apple_wallet_link' => $appleWalletLink ?? '',
            ];

            if ($application['esncard']) {
                $this->emailManager->sendEmail($application['email'], 'both_approval', $emailFields);
            } else {
                $this->emailManager->sendEmail($application['email'], 'pass_approval', $emailFields);
            }

            $this->logger->notice('Approved submission @id.', ['@id' => $id]);
            return;
        } catch (Exception $e) {
            $this->logger->error('Updating Application @id failed: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            throw new Exception('Failed to update application');
        }
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