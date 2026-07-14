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
use Drupal\esn_membership_manager\Config\ModuleSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
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
 *   label = @Translation("Approve Application"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class ApproveApplication extends ActionBase implements ContainerFactoryPluginInterface
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
    public function execute(?ApplicationInterface $application = null): void
    {
        if (empty($application)) {
            return;
        }

        if ($application->getApprovalStatus() != 'Pending' && $application->getApprovalStatus() != 'Rejected') {
            $this->logger->warning('Application @id cannot be marked as approved because its current status is @status.',
                [
                    '@id' => $application->id(),
                    '@status' => $application->getApprovalStatus()
                ]
            );
            throw new Exception('This status cannot be applied');
        }

        $moduleSettings = new ModuleSettings($this->configFactory);

        $token = strtoupper(md5(uniqid(rand(), true)));

        $application
            ->setValue(ApplicationField::PassToken, $token)
            ->setValue(ApplicationField::ApprovalStatus, 'Approved')
            ->setValue(ApplicationField::DateApproved, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

        $emailFields = [
            'name' => $application->getValue(ApplicationField::Name),
            'pass_token' => $token,
        ];

        if ($application->getValue(ApplicationField::HasESNcard)) {
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
                    'Application @id requested ESNcard but none are available.',
                    ['@id' => $application->id()]
                );
                throw new Exception('No available ESNcards');
            }

            try {
                $mobilityStatus = $application->getValue(ApplicationField::MobilityStatus);
                $isESNer = $mobilityStatus == 'ESN Volunteer' || $mobilityStatus == 'ESN Alumnus';

                $paymentLink = $this->stripeService->createApplicationPaymentLink($application->id(), $isESNer);
            } catch (ApiErrorException $e) {
                $this->logger->error('Stripe API error for application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
                throw new Exception('Stripe API Error');
            }

            if (!$paymentLink) {
                $this->logger->error('Failed to create payment link for application @id.', ['@id' => $application->id()]);
                throw new Exception('Failed to create payment link');
            }

            $application
                ->setValue(ApplicationField::PaymentLink, $paymentLink->url)
                ->setValue(ApplicationField::PaymentLinkID, $paymentLink->id);

            $emailFields += ['payment_link' => $paymentLink->url];
        }

        try {
            $application->save();

            if ($moduleSettings->getGoogleWalletSwitch()) {
                $googleWalletLink = Url::fromRoute(
                    'esn_membership_manager.add_to_google_wallet',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            if ($moduleSettings->getAppleWalletSwitch()) {
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

            if ($application->getValue(ApplicationField::HasESNcard)) {
                $this->emailManager->sendEmail($application->getValue(ApplicationField::Email), 'both_approval', $emailFields);
            } else {
                $this->emailManager->sendEmail($application->getValue(ApplicationField::Email), 'pass_approval', $emailFields);
            }

            $this->logger->notice('Approved application @id.', ['@id' => $application->id()]);
            return;
        } catch (Exception $e) {
            $this->logger->error('Updating Application @id failed: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to update application');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'approve applications');
        return $return_as_object ? $access : $access->isAllowed();
    }
}