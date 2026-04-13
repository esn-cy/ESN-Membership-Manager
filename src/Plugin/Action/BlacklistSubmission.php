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
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blacklists a Submission.
 *
 * @Action(
 *   id = "esn_membership_manager_blacklist",
 *   label = @Translation("Blacklist Submission"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class BlacklistSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected Connection $database;
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        Connection                    $database,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
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

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $database,
            $emailManager,
            $loggerFactory,
            $stripeService
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute($id = NULL): void
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

        if ($application['esncard']) {
            if ($application['approval_status'] != "Approved") {
                $this->logger->warning('Application @id cannot be blacklisted.', ['@id' => $id]);
                throw new Exception('This status cannot be applied');
            }
            $this->stripeService->disablePaymentLink($id);
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => 'Blacklisted',
                ])
                ->condition('id', $id)
                ->execute();

            $this->emailManager->sendEmail($application['email'], 'pass_blacklist', ['name' => $application['name']]);

            $this->logger->notice('Blacklisted submission @id', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Unable to blacklist submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete blacklisting process');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'blacklist submission');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
