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

    public function __construct(
        array                         $configuration, $plugin_id, $plugin_definition,
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        FileSystemInterface           $fileSystem,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configuration, $plugin_id, $plugin_definition);
        $this->database = $database;
        $this->entityTypeManager = $entityTypeManager;
        $this->fileSystem = $fileSystem;
        $this->logger = $loggerFactory->get('esn_membership_manager');
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

        return new static(
            $configuration,
            $plugin_id,
            $plugin_definition,
            $database,
            $entityTypeManager,
            $fileSystem,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
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
            return;
        }

        if (empty($application)) {
            $this->logger->warning('Application @id was not found', ['@id' => $id]);
            return;
        }

        try {
            $this->deleteFile($application['proof_fid']);
            $this->deleteFile($application['id_document_fid']);
            $this->deleteFile($application['face_photo_fid']);

            $directory = 'membership://' . $id;
            $this->fileSystem->deleteRecursive($directory);

            $this->database->delete('esn_membership_manager_applications')
                ->condition('id', $id)
                ->execute();
            $this->logger->notice('Deleted submission @id', ['@id' => $id]);
        } catch (Exception $e) {
            $this->logger->error('Unable to delete submission @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
            return;
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
