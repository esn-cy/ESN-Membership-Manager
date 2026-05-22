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
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declines an application.
 *
 * @Action(
 *   id = "esn_membership_manager_deliver",
 *   label = @Translation("Deliver Cards"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class DeliverCard extends ActionBase implements ContainerFactoryPluginInterface
{
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
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

        if (!$application->getValue(ApplicationField::HasESNcard) || $application->getValue(ApplicationField::ApprovalStatus) != 'Issued') {
            $this->logger->warning('Application @id cannot be marked as delivered because its current status is @status.',
                [
                    '@id' => $application->id(),
                    '@status' => $application->getValue(ApplicationField::ApprovalStatus)
                ]
            );
            throw new Exception('This status cannot be applied');
        }

        try {
            $application->setValue(ApplicationField::ApprovalStatus, 'Delivered');
            $application->save();

            $this->logger->notice('Delivered application @id', ['@id' => $application->id()]);
        } catch (Exception $e) {
            $this->logger->error('Unable to mark card as delivered @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete delivery process');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'deliver cards');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
