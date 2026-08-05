<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rejects an application.
 *
 * @Action(
 *   id = "esn_membership_manager_reject",
 *   label = @Translation("Reject Application"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class RejectApplication extends ActionBase implements ContainerFactoryPluginInterface
{
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->emailManager = $emailManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $emailManager,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute(?ApplicationInterface $application = null, ?string $reasons = null): void
    {
        if (empty($application)) {
            return;
        }

        $issues = $application->addApprovalStatus(ApprovalStatuses::Rejected);
        if (is_string($issues)) {
            $this->logger->warning('Application @id cannot be marked as rejected. @issues.',
                [
                    '@id' => $application->id(),
                    '@issues' => $issues
                ]
            );
            throw new Exception('This status cannot be applied.');
        }
        $application->clearPendingStatuses();

        try {
            $application->save();

            $emailReasons = [];
            if (!empty($reasons) && $reasons !== 'Rejected') {
                $reasonsSplit = explode('/', $reasons);
                foreach ($reasonsSplit as $reason) {
                    if (str_starts_with($reason, 'Rejected-')) {
                        $reasonParts = explode('-', $reason);
                        $formatedReason = $reasonParts[1];
                        if ($reasonParts[1] == 'Status' || $reasonParts[1] == 'Identity') {
                            $formatedReason .= ' Document';
                        }
                        $formatedReason .= ': ' . $reasonParts[2];
                        if ($reasonParts[2] == 'Local') {
                            $formatedReason .= ' Student';
                        }
                        if ($reasonParts[2] == 'Duplicate') {
                            $formatedReason .= ' Application';
                        }

                        $emailReasons[] = $formatedReason;
                    }
                }
            }

            $this->emailManager->sendEmail($application->getValue(ApplicationField::Email), 'both_rejection', [
                'name' => $application->getValue(ApplicationField::Name),
                'reasons' => $emailReasons
            ]);

            $this->logger->notice('Rejected application @id', ['@id' => $application->id()]);
        } catch (Exception $e) {
            $this->logger->error('Unable to reject application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete rejection process');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'reject applications');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
