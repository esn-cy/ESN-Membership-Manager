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
use Drupal\esn_membership_manager\Mail\BlacklistEmail;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Blacklists an Application.
 *
 * @Action(
 *   id = "esn_membership_manager_blacklist",
 *   label = @Translation("Blacklist Application"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class BlacklistApplication extends ActionBase implements ContainerFactoryPluginInterface
{
    protected EmailService $emailService;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        EmailService $emailService,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->emailService = $emailService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
    }

    public static function create(
        ContainerInterface $container,
        array              $configuration, $plugin_id, $plugin_definition
    ): self
    {
        /** @var EmailService $emailService */
        $emailService = $container->get('omnia.email_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $emailService,
            $loggerFactory,
            $stripeService,
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

        $issues = $application->addApprovalStatus(ApprovalStatuses::Blacklisted);
        if (is_string($issues)) {
            $this->logger->warning('Application @id cannot be marked as blacklisted. @issues.',
                [
                    '@id' => $application->id(),
                    '@issues' => $issues
                ]
            );
            throw new Exception('This status cannot be applied.');
        }

        if ($application->getValue(ApplicationField::HasESNcard)) {
            $this->stripeService->disablePaymentLink($application->id());
        }

        try {
            $application->save();

            $email = new BlacklistEmail(
                name: $application->getValue(ApplicationField::Name)
            );

            $this->emailService->send($application->getValue(ApplicationField::Email), $email);

            $this->logger->notice('Blacklisted application @id', ['@id' => $application->id()]);
        } catch (Exception $e) {
            $this->logger->error('Unable to blacklist application @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete blacklisting process');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'blacklist applications');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
