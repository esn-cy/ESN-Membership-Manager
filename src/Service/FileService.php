<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\NotRegularDirectoryException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\file\FileUsage\DatabaseFileUsageBackend;
use Exception;
use finfo;
use ValueError;

/**
 * Service for managing files within the ESN Membership Manager module.
 *
 * Provides utility methods for loading, reading, moving, saving, replacing, and deleting managed files and directories in Drupal.
 */
class FileService
{
    protected EntityTypeManagerInterface $entityTypeManager;
    protected FileSystemInterface $fileSystem;
    protected FileRepositoryInterface $fileRepository;
    protected DatabaseFileUsageBackend $fileUsage;
    protected LoggerChannelInterface $logger;

    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        FileSystemInterface           $fileSystem,
        FileRepositoryInterface       $fileRepository,
        DatabaseFileUsageBackend      $fileUsage,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->fileSystem = $fileSystem;
        $this->fileRepository = $fileRepository;
        $this->fileUsage = $fileUsage;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * Gets the web-accessible URL for a file.
     *
     * @param int|string|null $fileID The ID of the file entity.
     *
     * @return string|null The relative URL to the file, or `null` if the file cannot be found.
     */
    public function getFileURL(int|string|null $fileID): ?string
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return null;
        }

        return $file->createFileUrl(FALSE);
    }

    /**
     * Loads a file entity by its ID.
     *
     * @param int|string|null $fileID The ID of the file entity to load.
     *
     * @return FileInterface|null The loaded file entity, or `null` if it cannot be loaded or is invalid.
     */
    private function getFile(int|string|null $fileID): ?FileInterface
    {
        if (empty($fileID)) {
            return null;
        }

        try {
            $file = $this->entityTypeManager->getStorage('file')->load($fileID);
        } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
            $this->logger->error('File @id access error: @error', ['@id' => $fileID, '@error' => $e->getMessage()]);
            return null;
        }
        if (!$file instanceof FileInterface) {
            return null;
        }
        return $file;
    }

    /**
     * Resets the cache for a specific file.
     *
     * @param int|string|null $fileID The ID of the file entity to reset.
     */
    private function resetFileCache(int|string|null $fileID): void
    {
        try {
            $this->entityTypeManager->getStorage('file')->resetCache([$fileID]);
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
        }
    }

    /**
     * Gets the MIME type of a file.
     *
     * @param int|string|null $fileID The ID of the file entity.
     *
     * @return string|null The MIME type of the file, or `null` if the file cannot be found.
     */
    public function getFileMimeType(int|string|null $fileID): ?string
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return null;
        }

        return $file->getMimeType();
    }

    /**
     * Checks whether a file entity exists for the given ID.
     *
     * @param int|string|null $fileID The ID of the file entity.
     *
     * @return bool True if the file exists, false otherwise.
     */
    public function fileExists(int|string|null $fileID): bool
    {
        $file = $this->getFile($fileID);
        return !empty($file);
    }

    /**
     * Reads and returns the raw contents of a file.
     *
     * @param int|string|null $fileID The ID of the file entity.
     *
     * @return string|null The file contents, or `null` if the file cannot be read or found.
     */
    public function readFile(int|string|null $fileID): ?string
    {
        $filePath = $this->getFilePath($fileID);
        if (empty($filePath)) {
            return null;
        }

        return file_get_contents($filePath) ?? null;
    }

    /**
     * Gets the absolute local filesystem path of a file.
     *
     * @param int|string|null $fileID The ID of the file entity.
     *
     * @return string|null  The absolute path to the file, or `null` if the file cannot be found.
     */
    public function getFilePath(int|string|null $fileID): ?string
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return null;
        }

        return $this->fileSystem->realpath($file->getFileUri());
    }

    /**
     * Creates a file in the filesystem and creates a file entity.
     *
     * @param string $fileData The file data to be saved.
     * @param string $directory The directory to save the file in.
     * @param string $fileName The name of the created file.
     * @param int|string|null $applicationID The ID of the application that the file belongs to.
     *
     * @return string|null  The file ID of the created file, or `null` if the file cannot be created.
     */
    public function createFile(string $fileData, string $directory, string $fileName, int|string|null $applicationID): ?string
    {
        if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
            $this->logger->error('Failed to create or prepare directory: @directory', ['@directory' => $directory]);
            return null;
        }

        try {
            /** @noinspection PhpFullyQualifiedNameUsageInspection */
            /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
            /** @noinspection PhpDeprecationInspection */
            $existsBehavior = class_exists('\Drupal\Core\File\FileExists')
                ? \Drupal\Core\File\FileExists::Replace // Drupal 10.3+
                : \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE; // Drupal 9 / <10.3

            $file = $this->fileRepository->writeData($fileData, "$directory/$fileName", $existsBehavior);

            if ($this->saveFile($file->id(), $applicationID)) {
                return $file->id();
            } else {
                return null;
            }
        } catch (FileException|ValueError|EntityStorageException $e) {
            $this->logger->error('File @name creation error: @error', ['@name' => $fileName, '@error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Sets a file's status to permanent, saves it, and adds a usage record.
     *
     * @param int|string|null $fileID The ID of the file entity.
     * @param int|string|null $applicationID The ID of the application that the file belongs to.
     *
     * @return bool True if the file was successfully saved and marked as used, false otherwise.
     */
    public function saveFile(int|string|null $fileID, int|string|null $applicationID): bool
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return false;
        }

        $file->setPermanent();
        try {
            $file->save();
        } catch (EntityStorageException $e) {
            $this->logger->error('File @id save error: @error', ['@id' => $fileID, '@error' => $e->getMessage()]);
            return false;
        }

        if (!empty($applicationID)) {
            $this->fileUsage->add($file, 'esn_membership_manager', 'membership_application', $applicationID);
        }

        return true;
    }

    /**
     * Deletes a file entity and its associated usage records.
     *
     * @param int|string|null $fileID The ID of the file entity.
     * @param int|string|null $applicationID The ID of the application that the file belongs to.
     *
     * @return bool True if the file was successfully deleted, false otherwise.
     */
    public function deleteFile(int|string|null $fileID, int|string|null $applicationID): bool
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return false;
        }

        try {
            $file->delete();
            if (!empty($applicationID)) {
                $this->fileUsage->delete($file, 'esn_membership_manager', 'membership_application', $applicationID, 0);
            }
            return true;
        } catch (Exception $e) {
            $this->logger->error('File @id delete error: @error', ['@id' => $fileID, '@error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Checks if a directory is empty.
     *
     * @param string $path The URI or path to the directory.
     *
     * @return bool True if the directory is empty, false if not.
     */
    public function isDirectoryEmpty(string $path): bool
    {
        if (!str_starts_with($path, 'membership://')) {
            return false;
        }

        try {
            $contents = $this->fileSystem->scanDirectory($path, '/.*/', ['recurse' => false]);

            return empty($contents);
        } catch (NotRegularDirectoryException) {
            return false;
        }
    }

    /**
     * Recursively deletes a directory from the filesystem.
     *
     * Contains a safeguard to prevent deletion of paths not starting with the 'membership://' scheme.
     *
     * @param string $path The URI or path to the directory.
     *
     * @return bool True if the directory was deleted, false if the path was restricted.
     */
    public function deleteDirectory(string $path): bool
    {
        if (!str_starts_with($path, 'membership://')) {
            return false;
        }
        $this->fileSystem->deleteRecursive($path);
        return true;
    }

    /**
     * Moves a file to a new directory and optionally renames it.
     *
     * @param int|string|null $fileID The ID of the file entity.
     * @param string $directory The destination directory URI.
     * @param string|null $renameTo (Optional) The new base filename without the extension.
     *
     * @return bool True if the file was moved successfully, false otherwise.
     */
    public function moveFile(int|string|null $fileID, string $directory, string $renameTo = null): bool
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return false;
        }

        if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
            $this->logger->error('Failed to create or prepare directory: @directory', ['@directory' => $directory]);
            return false;
        }

        $filename = $file->getFilename();
        if ($renameTo) {
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $filename = $renameTo . '.' . $extension;
        }
        try {
            $this->fileRepository->move($file, $directory . '/' . $filename);
            $this->resetFileCache($fileID);
            return true;
        } catch (EntityStorageException $e) {
            $this->logger->error('File @id move error: @error', ['@id' => $fileID, '@error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Replaces the contents of a file with new data.
     *
     * Automatically detects the MIME type and extension of the new data.
     * Updates the file entity's URI, filename, MIME type, and size accordingly.
     * Replaces the physical file and updates Drupal's file cache.
     *
     * @param int|string|null $fileID The ID of the file entity to update.
     * @param string $fileData The raw binary data to write to the file.
     *
     * @return bool True if the file was replaced successfully, false on error or unsupported MIME type.
     */
    public function replaceFileData(int|string|null $fileID, string $fileData): bool
    {
        $file = $this->getFile($fileID);
        if (empty($file)) {
            return false;
        }

        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->buffer($fileData);

        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',
            default => null,
        };

        if (!$extension) {
            $this->logger->error('File @id replace data error: Unsupported MIME type @mime', ['@id' => $fileID, '@mime' => $mimeType]);
            return false;
        }

        $currentURI = $file->getFileUri();
        $pathInfo = pathinfo($currentURI);
        $nameInfo = pathinfo($file->getFilename());
        $uri = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.' . $extension;
        $filename = $nameInfo['filename'] . '.' . $extension;

        try {
            /** @noinspection PhpFullyQualifiedNameUsageInspection */
            /** @noinspection PhpUnnecessaryFullyQualifiedNameInspection */
            /** @noinspection PhpDeprecationInspection */
            $existsBehavior = class_exists('\Drupal\Core\File\FileExists')
                ? \Drupal\Core\File\FileExists::Replace // Drupal 10.3+
                : \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE; // Drupal 9 / <10.3

            $this->fileSystem->saveData($fileData, $uri, $existsBehavior);

            $file->setFileUri($uri);
            $file->setFilename($filename);
            $file->setMimeType($mimeType);
            $file->setSize(strlen($fileData));
            $file->save();

            if ($currentURI !== $uri && file_exists($currentURI)) {
                $this->fileSystem->delete($currentURI);
            }

            $this->resetFileCache($fileID);

            return true;
        } catch (EntityStorageException $e) {
            $this->logger->error('File @id replace data error: @error', ['@id' => $fileID, '@error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Generates a path for a temporary file.
     *
     * @param string|null $prefix (Optional) A prefix to the temporary filename.
     * @param string|null $extension (Optional) An extension to append to the filename.
     *
     * @return string The absolute path to the generated temporary file.
     */
    public function getTemporaryFile(?string $prefix = null, ?string $extension = null): string
    {
        $temporaryDirectory = $this->fileSystem->getTempDirectory();
        return $this->fileSystem->tempnam($temporaryDirectory, $prefix) . $extension;
    }
}