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
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks an application as paid.
 *
 * @Action(
 *   id = "esn_membership_manager_mark_paid",
 *   label = @Translation("Mark Application as Paid"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class MarkApplicationAsPaid extends ActionBase implements ContainerFactoryPluginInterface
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
        ESNcardService                $esncardService,
        StripeService                 $stripeService,
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
    public function execute(?ApplicationInterface $application = null, bool $isManual = true): string
    {
        if (empty($application)) {
            $this->logger->warning('Mark Application as Paid executed without a valid Application.');
            return 'Did not run due to an empty Application';
        }

        if (!$this->lock->acquire('process_application_' . $application->id())) {
            $this->logger->warning('Could not acquire lock for application @id. Another process may be running.', ['@id' => $application->id()]);
            return 'Did not run due to an error acquiring a lock';
        }

        if (!empty($application->getValue(ApplicationField::ESNcardNumber)) && $application->getApprovalStatus() == 'Paid') {
            $this->logger->warning(
                'Application @id was already paid. Duplicate payment event detected.',
                ['@id' => $application->id()]
            );
            $this->lock->release('process_application_' . $application->id());
            return 'Duplicate payment event detected';
        }

        $isManual = empty($linkID);

        $transaction = $this->database->startTransaction();

        try {
            $cardNumber = $this->esncardService->assignESNcardNumber($application, $isManual);
        } catch (Exception $e) {
            $this->logger->error('Failed to assign an ESNcard number to application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            $transaction->rollBack();
            $this->lock->release('process_application_' . $application->id());
            throw new Exception('Failed to assign an ESNcard number');
        }

        try {
            $application
                ->setValue(ApplicationField::ApprovalStatus, 'Paid')
                ->setValue(ApplicationField::DatePaid, (new DrupalDateTime())->format('Y-m-d\TH:i:s'))
                ->setValue(ApplicationField::ESNcardNumber, $cardNumber);

            $application->save();
        } catch (Exception $e) {
            $this->logger->error('Failed to update application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            $transaction->rollBack();
            $this->lock->release('process_application_' . $application->id());
            throw new Exception('Failed to update application');
        }

        if (!empty($application->getValue(ApplicationField::PaymentLinkID))) {
            try {
                $this->stripeService->disablePaymentLink($application->getValue(ApplicationField::PaymentLinkID));
            } catch (Exception $e) {
                $this->logger->error(
                    'Application @id processed, but failed to deactivate Stripe Payment Link @linkID: @message',
                    [
                        '@id' => $application->id(),
                        '@linkID' => $application->getValue(ApplicationField::PaymentLinkID),
                        '@message' => $e->getMessage()
                    ]
                );
            }
        }

        unset($transaction);

        $this->logger->notice('Application @id marked as Paid and assigned ESNcard number.', ['@id' => $application->id()]);

        $this->esncardService->postAssignment($application, $isManual);

        $this->lock->release('process_application_' . $application->id());

        return 'ESNcard payment was processed successfully';
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'mark applications as paid');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
