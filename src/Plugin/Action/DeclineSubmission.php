<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declines a webform submission.
 *
 * @Action(
 *   id = "esn_membership_manager_decline",
 *   label = @Translation("Decline Submissions"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class DeclineSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected Connection $database;
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        Connection                    $database,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $database,
            $emailManager,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute($id = NULL): void
    {
        if (empty($id)) {
            return;
        }

        try {
            $email = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['email'])
                ->condition('id', $id)
                ->execute()
                ->fetchField();
        } catch (Exception $e) {
            $this->logger->error('Failed to load application email @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            return;
        }

        if (empty($email)) {
            $this->logger->warning('No email found for declined application @id', ['@id' => $id]);
            return;
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields(['approval_status' => 'Declined'])
                ->condition('id', $id)
                ->execute();
            $this->logger->notice('Declined submission @id', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Unable to decline submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            return;
        }
        $this->emailManager->sendEmail($email, 'both_denial', []);
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
