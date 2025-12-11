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
    protected LoggerChannelInterface $logger;

    public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, Connection $database, LoggerChannelFactoryInterface $logger_factory)
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $config_factory;
        $this->database = $database;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
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

        if (!in_array('ESNcard', $data['choices'])) {
            try {
                $entity->setElementData('approval_status', 'Approved');
                $entity->setElementData('pass_is_enabled', 1);
                $entity->save();
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

        $module_config = $this->configFactory->get('esn_membership_manager.settings');
        $stripeSecretKey = $module_config->get('stripe_secret_key');
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
        $module_config = $this->configFactory->get('esn_membership_manager.settings');
        $esnCardPriceID = $module_config->get('stripe_price_id_esncard');
        $processingFeePriceID = $module_config->get('stripe_price_id_processing');

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