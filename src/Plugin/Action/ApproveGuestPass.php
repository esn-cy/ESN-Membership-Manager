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
use Drupal\esn_membership_manager\Mail\GuestPassApprovalEmail;
use Drupal\omnia\Service\EmailService;
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
    protected MembershipSettings $membershipSettings;
    protected EmailService $emailService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $configFactory,
        EmailService $emailService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->emailService = $emailService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var EmailService $emailService */
        $emailService = $container->get('omnia.email_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $emailService,
            $loggerFactory,
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

        $token = 'GUEST' . substr(strtoupper(md5(uniqid(rand(), true))), 0, 27);

        $guestPass
            ->setValue(GuestPassField::PassToken, $token)
            ->setValue(GuestPassField::DateApproved, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

        try {
            $guestPass->save();

            if ($this->membershipSettings->getGoogleWalletSwitch()) {
                $googleWalletLink = Url::fromRoute(
                    'esn_membership_manager.add_to_google_wallet',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            if ($this->membershipSettings->getAppleWalletSwitch()) {
                $appleWalletLink = Url::fromRoute(
                    'esn_membership_manager.download_apple_pass',
                    ['identifier' => $token],
                    ['absolute' => TRUE]
                )->toString();
            }

            $email = new GuestPassApprovalEmail(
                name: $guestPass->getValue(GuestPassField::Name),
                passToken: $token,
                googleWalletLink: $googleWalletLink ?? null,
                appleWalletLink: $appleWalletLink ?? null,
            );

            $this->emailService->send($guestPass->getValue(GuestPassField::Email), $email);

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