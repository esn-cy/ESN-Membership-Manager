<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\WeeztixApiService;
use Exception;
use Stripe\PaymentLink;
use Stripe\Stripe;
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
    protected ConfigFactoryInterface $configFactory;

    protected Connection $database;
    protected LockBackendInterface $lock;
    protected LoggerChannelInterface $logger;
    protected EmailManager $emailManager;
    protected WeeztixApiService $weeztixService;
    protected GoogleService $googleService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        LockBackendInterface          $lock,
        LoggerChannelFactoryInterface $loggerFactory,
        EmailManager                  $emailManager,
        WeeztixApiService             $weeztixService,
        GoogleService                 $googleService
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->lock = $lock;
        $this->emailManager = $emailManager;
        $this->weeztixService = $weeztixService;
        $this->googleService = $googleService;
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

        /** @var LockBackendInterface $lock */
        $lock = $container->get('lock');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var WeeztixApiService $weeztixService */
        $weeztixService = $container->get('esn_membership_manager.weeztix_api_service');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
            $lock,
            $loggerFactory,
            $emailManager,
            $weeztixService,
            $googleService
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute($applicationID = NULL, $linkID = NULL): void
    {
        if (empty($applicationID)) {
            $this->logger->warning('MarkSubmissionAsPaid executed without a valid Application ID.');
            return;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        if (!$this->lock->acquire('process_application_' . $applicationID)) {
            $this->logger->warning('Could not acquire lock for application @id. Another process may be running.', ['@id' => $applicationID]);
            return;
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

        if (!$application) {
            $this->logger->warning('Application @id was not found.', ['@id' => $applicationID]);
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Application not found');
        }

        if ($application['approval_status'] == 'Paid' && !empty($application['esncard_number'])) {
            $this->logger->warning(
                'Application @id was already paid. Duplicate payment event detected.',
                ['@id' => $applicationID]
            );
            $this->lock->release('process_application_' . $applicationID);
            return;
        }

        try {
            $esnCard = $this->assignESNcardNumber($applicationID);
        } catch (Exception $e) {
            $this->logger->error('Failed to assign an ESNcard number to application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Failed to assign an ESNcard number');
        }

        try {
            $datePaid = (new DrupalDateTime())->format('Y-m-d H:i:s');
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => 'Paid',
                    'date_paid' => $datePaid,
                    'esncard_number' => $esnCard
                ])
                ->condition('id', $applicationID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Failed to update application @id: @message', ['@id' => $applicationID, '@message' => $e->getMessage()]);
            $this->lock->release('process_application_' . $applicationID);
            throw new Exception('Failed to update application');
        }

        $application['approval_status'] = 'Paid';
        $application['date_paid'] = $datePaid;
        $application['esncard_number'] = $esnCard;

        if (empty($linkID) && !empty($application['payment_link_id'])) {
            $linkID = $application['payment_link_id'];
        }

        if (!empty($linkID)) {
            try {
                $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
                if (empty($stripeSecretKey)) {
                    $this->logger->error('Stripe Secret Key not set in the module configuration.');
                    throw new Exception('Stripe Secret Key not set');
                }
                Stripe::setApiKey($stripeSecretKey);
                PaymentLink::update(
                    $linkID,
                    ['active' => false]
                );
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

        $this->logger->notice('Application @id marked as Paid and assigned ESNcard number.', ['@id' => $applicationID]);

        if ($moduleConfig->get('switch_weeztix') ?? FALSE) {
            $this->weeztixService->addCoupon($esnCard, ['applies_to_count' => 1]);
        }

        if ($moduleConfig->get('switch_google_sheets') ?? FALSE) {
            $this->googleService->appendRow(
                [
                    'date' => str_replace('-', '/', date('d-m-y')),
                    'name' => $application['name'] . ' ' . $application['surname'],
                    'card_number' => $esnCard,
                    'pos' => 'ESN Membership Manager',
                    'host' => $application['host_institution'],
                    'nationality' => $application['nationality'],
                    'mop' => 'Stripe',
                    'amount' => 16,
                ]
            );
        }

        if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
            try {
                $googleWalletLink = $this->googleService->getESNcardObject($application);
            } catch (\Google\Service\Exception $e) {
                $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
            } catch (Exception $e) {
                $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
            }
        }

        $emailParams = [
            'name' => $application['name'],
            'esncard_number' => $esnCard,
            'google_wallet_link' => $googleWalletLink ?? '',
        ];
        $this->emailManager->sendEmail($application['email'], 'card_assignment', $emailParams);

        $this->lock->release('process_application_' . $applicationID);
    }

    /**
     * Assigns the next available ESNcard number to a submission.
     * @throws Exception
     */
    private function assignESNcardNumber($applicationID): string
    {
        $transaction = $this->database->startTransaction();

        try {
            $query = $this->database->select('esn_membership_manager_cards', 'e')
                ->fields('e', ['number'])
                ->condition('assigned', 0)
                ->orderBy('id')
                ->range(0, 1)
                ->forUpdate();

            /** @noinspection PhpPossiblePolymorphicInvocationInspection */
            $nextNumber = $query->execute()->fetchField();

            if ($nextNumber) {
                $this->database->update('esn_membership_manager_cards')
                    ->fields(['assigned' => 1])
                    ->condition('number', $nextNumber)
                    ->execute();

                $this->logger->notice('Assigned ESNcard number @num to application @id.', [
                    '@num' => $nextNumber,
                    '@id' => $applicationID,
                ]);
                return $nextNumber;
            } else {
                $this->logger->warning('No available ESNcard numbers left to assign.');
                throw new Exception('Failed to assign ESNcard number: No available ESNcard numbers left to assign.');
            }
        } catch (Exception $e) {
            $transaction->rollBack();
            $this->logger->error('Failed to assign ESNcard number: @message', ['@message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'mark submission as paid');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
