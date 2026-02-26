<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\file\FileInterface;
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
    protected Connection $database;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected FileSystemInterface $fileSystem;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        FileSystemInterface           $fileSystem,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
        $this->entityTypeManager = $entityTypeManager;
        $this->fileSystem = $fileSystem;
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

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var FileSystemInterface $fileSystem */
        $fileSystem = $container->get('file_system');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $database,
            $entityTypeManager,
            $fileSystem,
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

        try {
            $this->deleteFile($application['proof_fid']);
            $this->deleteFile($application['id_document_fid']);
            $this->deleteFile($application['face_photo_fid']);

            $directory = 'membership://' . $id;
            $this->fileSystem->deleteRecursive($directory);

            if ($application['esncard'] && $application['approval_status'] == "Approved") {
                $this->stripeService->disablePaymentLink($id);
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
     * Helper to delete a file.
     */
    protected function deleteFile($fid): void
    {
        if (empty($fid)) {
            return;
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fid);
            $file?->delete();
        } catch (Exception $e) {
            $this->logger->error('Error deleting file @fid: @message', [
                '@fid' => $fid,
                '@message' => $e->getMessage()
            ]);
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
