<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declines a webform submission.
 *
 * @Action(
 *   id = "esn_membership_manager_decline",
 *   label = @Translation("Decline Submissions"),
 *   type = "webform_submission",
 *   confirm = TRUE
 * )
 */
class DeclineSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;

    public function __construct(array                         $configuration, $plugin_id, $plugin_definition,
                                EmailManager                  $emailManager,
                                LoggerChannelFactoryInterface $logger_factory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->emailManager = $emailManager;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
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
     */
    public function execute($entity = NULL): void
    {
        if (!($entity instanceof WebformSubmissionInterface)) {
            return;
        }

        $data = $entity->getData();

        try {
            $entity->setElementData('approval_status', 'Declined');
            $entity->save();
            $this->logger->notice('Declined submission @id', ['@id' => $entity->id()]);
        } catch (EntityStorageException) {
            $this->logger->notice('Unable to save declined submission @id', ['@id' => $entity->id()]);
        }
        $this->emailManager->sendEmail($data['name'], 'both_denial', []);
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'decline submission');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
