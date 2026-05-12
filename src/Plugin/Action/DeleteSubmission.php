<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Declines an application.
 *
 * @Action(
 *   id = "esn_membership_manager_delete",
 *   label = @Translation("Delete Submissions"),
 *   type = "system",
 *   confirm = TRUE
 * )
 */
class DeleteSubmission extends ActionBase implements ContainerFactoryPluginInterface
{
    protected ConfigFactoryInterface $configFactory;
    protected Connection $database;
    protected FileService $fileService;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;
    protected GoogleService $googleService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        ConfigFactoryInterface $configFactory,
        Connection                    $database,
        FileService            $fileService,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService          $stripeService,
        GoogleService          $googleService
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->fileService = $fileService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
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

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $configFactory,
            $database,
            $fileService,
            $loggerFactory,
            $stripeService,
            $googleService
        );
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function execute(?int $id = NULL): void
    {
        if (empty($id)) {
            return;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

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

        try {
            $deletedFiles = 0;
            foreach (['proof_fid', 'id_document_fid', 'face_photo_fid'] as $fileName) {
                if (!$application['esncard'] && $fileName != 'proof_fid') {
                    continue;
                }

                if (empty($application[$fileName])) {
                    $deletedFiles++;
                    continue;
                }

                try {
                    $count = $this->database->select('esn_membership_manager_applications', 'a')
                        ->condition($fileName, $application[$fileName])
                        ->countQuery()
                        ->execute()
                        ->fetchField();
                    if ($count <= 1) {
                        $this->fileService->deleteFile($application[$fileName]);
                        $deletedFiles++;
                    }
                } catch (Exception $e) {
                    $this->logger->error('Failed check if file is present in another application. Skipping', ['@id' => $id, '@message' => $e->getMessage()]);
                }
            }
            if (($application['esncard'] && $deletedFiles == 3) || (!$application['esncard'] && $deletedFiles == 1)) {
                $this->fileService->deleteDirectory('membership://' . $id);
            }

            if ($application['esncard'] && $application['approval_status'] == "Approved") {
                $this->stripeService->disablePaymentLink($id);
            }

            if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                if ($application['esncard']) {
                    $this->googleService->deleteObject($application['id'], 'card');
                }
                $this->googleService->deleteObject($application['id'], 'pass');
            }

            $this->database->delete('esn_membership_manager_applications')
                ->condition('id', $id)
                ->execute();

            $this->logger->notice('Deleted submission @id', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Unable to delete submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            throw new Exception('Failed to complete deletion process');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface
    {
        $access = AccessResult::allowedIfHasPermission($account, 'delete submission');
        return $return_as_object ? $access : $access->isAllowed();
    }
}
