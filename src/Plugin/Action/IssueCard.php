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
use Drupal\esn_membership_manager\Mail\CardIssuanceEmail;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Marks an application as Issued.
 *
 * @Action(
 *   id = "esn_membership_manager_issue",
 *   label = @Translation("Issue Cards"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class IssueCard extends ActionBase implements ContainerFactoryPluginInterface
{
    protected EmailService $emailService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        EmailService $emailService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->emailService = $emailService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
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

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $emailService,
            $loggerFactory,
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

        $issues = $application->addApprovalStatus(ApprovalStatuses::Issued);
        if (is_string($issues)) {
            $this->logger->warning('Application @id cannot be marked as issued. @issues.',
                [
                    '@id' => $application->id(),
                    '@issues' => $issues
                ]
            );
            throw new Exception('This status cannot be applied.');
        }

        try {
            $application->save();

            $this->logger->notice('Issued application @id', ['@id' => $application->id()]);
        } catch (Exception $e) {
            $this->logger->error('Unable to mark card as issued @id: @message', ['@id' => $application->id(), '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete issuance process');
        }

        $email = new CardIssuanceEmail(
            name: $application->getValue(ApplicationField::Name)
        );

        $this->emailService->send($application->getValue(ApplicationField::Email), $email);
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'issue cards');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
