<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approves a Guest Pass.
 *
 * @Action(
 *   id = "esn_membership_manager_approve_guest",
 *   label = @Translation("Approve Guest Pass"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class ApproveGuestPass extends ActionBase implements ContainerFactoryPluginInterface
{
    protected ConfigFactoryInterface $configFactory;
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $configFactory,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $configFactory;
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

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $emailManager,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute(?GuestPassInterface $guestPass = null): void
    {
        if (empty($guestPass)) {
            return;
        }

        $membershipSettings = new MembershipSettings($this->configFactory);

        $token = 'GUEST' . substr(strtoupper(md5(uniqid(rand(), true))), 0, 27);

        $guestPass
            ->setValue(GuestPassField::PassToken, $token)
            ->setValue(GuestPassField::DateApproved, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

        try {
            $guestPass->save();

            if ($membershipSettings->getGoogleWalletSwitch()) {
                $googleWalletLink = Url::fromRoute(
                    'esn_membership_manager.add_to_google_wallet',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            if ($membershipSettings->getAppleWalletSwitch()) {
                $appleWalletLink = Url::fromRoute(
                    'esn_membership_manager.download_apple_pass',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }


            $this->emailManager->sendEmail($guestPass->getValue(GuestPassField::Email), 'guest_approval', [
                'name' => $guestPass->getValue(GuestPassField::Name),
                'pass_token' => $token,
                'google_wallet_link' => $googleWalletLink ?? '',
                'apple_wallet_link' => $appleWalletLink ?? '',
            ]);

            $this->logger->notice('Approved guest pass @id.', ['@id' => $guestPass->id()]);
            return;
        } catch (Exception $e) {
            $this->logger->error('Updating Guest Pass @id failed: @message', ['@id' => $guestPass->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to update Guest Pass.');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'approve guest passes');
        return $return_as_object ? $access : $access->isAllowed();
    }
}