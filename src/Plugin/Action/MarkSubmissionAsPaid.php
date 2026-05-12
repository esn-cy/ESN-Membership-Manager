<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declines an application.
 *
 * @Action(
 *   id = "esn_membership_manager_mark_paid",
 *   label = @Translation("Mark Submissions as Paid"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class MarkSubmissionAsPaid extends ActionBase implements ContainerFactoryPluginInterface
{
    protected Connection $database;
    protected LockBackendInterface $lock;
    protected LoggerChannelInterface $logger;
    protected ESNcardService $esncardService;
    protected StripeService $stripeService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        Connection                    $database,
        LockBackendInterface          $lock,
        LoggerChannelFactoryInterface $loggerFactory,
        ESNcardService $esncardService,
        StripeService  $stripeService,
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->lock = $lock;
        $this->esncardService = $esncardService;
        $this->stripeService = $stripeService;
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LockBackendInterface $lock */
        $lock = $container->get('lock');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var ESNcardService $esncardService */
        $esncardService = $container->get('esn_membership_manager.esncard_service');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $database,
            $lock,
            $loggerFactory,
            $esncardService,
            $stripeService,
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute(?int $applicationID = NULL, ?string $linkID = NULL): string
    {
        if (empty($applicationID)) {
            $this->logger->warning('MarkSubmissionAsPaid executed without a valid Application ID.');
            return 'Did not run due to an empty Application ID';
        }

        if (!$this->lock->acquire('process_application_' . $applicationID)) {
            $this->logger->warning('Could not acquire lock for application @id. Another process may be running.', ['@id' => $applicationID]);
            return 'Did not run due to an error acquiring a lock';
        }

        try {
            $application = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('id', $applicationID)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Failed to load application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Failed to load application');
        }

        if (empty($application)) {
            $this->logger->warning('Application @id was not found.', ['@id' => $applicationID]);
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Application not found');
        }

        if (!empty($application['esncard_number']) && $application['approval_status'] == 'Paid') {
            $this->logger->warning(
                'Application @id was already paid. Duplicate payment event detected.',
                ['@id' => $applicationID]
            );
            $this->lock->release('process_application_' . $applicationID);
            return 'Duplicate payment event detected';
        }

        $isManual = empty($linkID);

        $transaction = $this->database->startTransaction();

        try {
            $cardNumber = $this->esncardService->assignESNcardNumber($applicationID, $isManual);
        } catch (Exception $e) {
            $this->logger->error('Failed to assign an ESNcard number to application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $transaction->rollBack();
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Failed to assign an ESNcard number');
        }

        try {
            $datePaid = (new DrupalDateTime())->format('Y-m-d H:i:s');
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => 'Paid',
                    'date_paid' => $datePaid,
                    'esncard_number' => $cardNumber
                ])
                ->condition('id', $applicationID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Failed to update application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $transaction->rollBack();
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Failed to update application');
        }

        $application['esncard_number'] = $cardNumber;

        if (empty($linkID) && !empty($application['payment_link_id'])) {
            $linkID = $application['payment_link_id'];
        }

        if (!empty($linkID)) {
            try {
                $this->stripeService->disablePaymentLink($linkID);
            } catch (Exception $e) {
                $this->logger->error(
                    'Application @id processed, but failed to deactivate Stripe Payment Link @linkID: @message',
                    [
                        '@id' => $applicationID,
                        '@linkID' => $linkID,
                        '@message' => $e->getMessage()
                    ]
                );
            }
        }

        unset($transaction);

        $this->logger->notice('Application @id marked as Paid and assigned ESNcard number.', ['@id' => $applicationID]);

        $this->esncardService->postAssignment($application, $isManual);

        $this->lock->release('process_application_' . $applicationID);

        return 'ESNcard payment was processed successfully';
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'mark submission as paid');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
