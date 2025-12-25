<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\webform\WebformSubmissionInterface;
use Exception;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentLink;
use Stripe\Stripe;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Approves a webform submission and creates a Stripe payment link.
 *
 * @Action(
 *   id = "esn_membership_manager_approve",
 *   label = @Translation("Approve Submissions"),
 *   type = "webform_submission",
 *   confirm = TRUE
 * )
 */
class ApproveSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected ConfigFactoryInterface $configFactory;
    protected Connection $database;
    protected EmailManager $emailManager;
    protected GoogleService $googleService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface        $config_factory,
        Connection                    $database,
        EmailManager                  $emailManager,
        GoogleService                 $googleService,
        LoggerChannelFactoryInterface $logger_factory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $config_factory;
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->googleService = $googleService;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
            $emailManager,
            $googleService,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     */
    public function execute($entity = null): void
    {
        if (!($entity instanceof WebformSubmissionInterface)) {
            return;
        }

        $data = $entity->getData();

        if (empty($data['choices'])) {
            return;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        if (!in_array('ESNcard', $data['choices'])) {
            try {
                $entity->setElementData('approval_status', 'Approved');
                $entity->setElementData('pass_is_enabled', 1);
                $entity->save();

                if (!empty($moduleConfig->get('google_issuer_id'))) {
                    try {
                        $google_wallet_link = $this->googleService->getFreePassObject($data);
                    } catch (\Google\Service\Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                    } catch (Exception $e) {
                        $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                    }
                }

                $email_params = [
                    'name' => $data['name'],
                    'token' => $data['user_token'],
                    'google_wallet_link' => $google_wallet_link ?? '',
                ];
                $this->emailManager->sendEmail($data['email'], 'pass_approval', $email_params);

                $this->logger->notice('Approved submission @id (no ESNcard requested).', ['@id' => $entity->id()]);
                return;
            } catch (EntityStorageException $e) {
                $this->logger->error('Updating Submission @id failed: @message', ['@id' => $entity->id(), '@message' => $e->getMessage()]);
                return;
            }
        }

        try {
            $query = $this->database->select('esn_membership_manager_cards', 'e');
            $query->addExpression('COUNT(*)', 'count');
            $query->condition('assigned', 0);
            $count = $query->execute()->fetchField();
        } catch (Exception $e) {
            $this->logger->error('Querying number of available ESNcards failed: @message.', ['@message' => $e->getMessage()]);
            return;
        }

        if ($count == 0) {
            $this->logger->warning(
                'Submission @id requested ESNcard but none are available.',
                ['@id' => $entity->id()]
            );
            return;
        }

        $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
        if (empty($stripeSecretKey)) {
            $this->logger->error('Stripe Secret Key not set in the module configuration.');
            return;
        }
        Stripe::setApiKey($stripeSecretKey);

        try {
            $paymentLink = $this->createStripePaymentLink($entity);
        } catch (ApiErrorException $e) {
            $this->logger->error('Stripe API error for submission @id: @message', ['@id' => $entity->id(), '@message' => $e->getMessage()]);
            return;
        }

        try {
            if ($paymentLink) {
                $entity->setElementData('payment_link', $paymentLink);
                $entity->setElementData('approval_status', 'Approved');
                $entity->setElementData('date_approved', (new DrupalDateTime())->format('Y-m-d H:i:s'));
                $entity->setElementData('pass_is_enabled', 1);
                $entity->save();

                $email_params = [
                    'name' => $data['name'],
                    'token' => $data['user_token'],
                    'payment_link' => $paymentLink,
                    'google_wallet_link' => ''
                ];

                if (in_array('pass', $data['choices'])) {
                    if (!empty($moduleConfig->get('google_issuer_id'))) {
                        try {
                            if (!empty($moduleConfig->get('google_issuer_id'))) {
                                $email_params['google_wallet_link'] = $this->googleService->getFreePassObject($data);
                            }
                        } catch (\Google\Service\Exception $e) {
                            $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getErrors()]);
                        } catch (Exception $e) {
                            $this->logger->warning('Google Wallet Error: @error.', ['@error' => $e->getMessage()]);
                        }
                    }
                    $this->emailManager->sendEmail($data['email'], 'both_approval', $email_params);
                } else {
                    $this->emailManager->sendEmail($data['email'], 'card_approval', $email_params);
                }

                $this->logger->notice('Approved submission @id and created payment link.', ['@id' => $entity->id()]);
            } else {
                $this->logger->error('Failed to create payment link for submission @id.', ['@id' => $entity->id()]);
            }
        } catch (EntityStorageException $e) {
            $this->logger->error('Failed to save submission @id after creating payment link: @message.', [
                '@id' => $entity->id(),
                '@message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Create a Stripe payment link for the given submission.
     *
     * @param WebformSubmissionInterface $entity
     *   The webform submission.
     *
     * @return string|null
     *   The payment link URL or null on failure.
     * @throws ApiErrorException
     */
    protected function createStripePaymentLink(WebformSubmissionInterface $entity): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $esnCardPriceID = $moduleConfig->get('stripe_price_id_esncard');
        $processingFeePriceID = $moduleConfig->get('stripe_price_id_processing');

        if (empty($esnCardPriceID) || empty($processingFeePriceID)) {
            $this->logger->error('Stripe Price IDs for ESNcard or Processing Fee are not configured.');
            return null;
        }

        $paymentLink = PaymentLink::create([
            'line_items' => [
                ['price' => $esnCardPriceID, 'quantity' => 1,],
                ['price' => $processingFeePriceID, 'quantity' => 1,]
            ],
            'metadata' => ['webform_submission_id' => (string)$entity->id(),]
        ]);

        return $paymentLink->url ?? null;
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'approve submission');
        return $return_as_object ? $access : $access->isAllowed();
    }
}