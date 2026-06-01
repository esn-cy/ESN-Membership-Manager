<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\esn_cyprus_core\Service\FileServiceBase;

/**
 * Service for managing files within the ESN Membership Manager module.
 *
 * Provides utility methods for loading, reading, moving, saving, replacing, and deleting managed files and directories in Drupal.
 */
class FileService extends FileServiceBase
{
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
    public function createApplicationFile(string $fileData, string $directory, string $fileName, int|string|null $applicationID): ?string
    {
        return $this->createFile($fileData, $directory, $fileName, 'esn_membership_manager', !empty($applicationID) ? ['membership_application' => $applicationID] : []);
    }

    /**
     * Sets a file's status to permanent, saves it, and adds a usage record.
     *
     * @param int|string|null $fileID The ID of the file entity.
     * @param int|string|null $applicationID The ID of the application that the file belongs to.
     *
     * @return bool True if the file was successfully saved and marked as used, false otherwise.
     */
    public function saveApplicationFile(int|string|null $fileID, int|string|null $applicationID): bool
    {
        return $this->saveFile($fileID, 'esn_membership_manager', !empty($applicationID) ? ['membership_application' => $applicationID] : []);
    }

    /**
     * Deletes a file entity and its associated usage records.
     *
     * @param int|string|null $fileID The ID of the file entity.
     * @param int|string|null $applicationID The ID of the application that the file belongs to.
     *
     * @return bool True if the file was successfully deleted, false otherwise.
     */
    public function deleteApplicationFile(int|string|null $fileID, int|string|null $applicationID): bool
    {
        return $this->deleteFile($fileID, 'esn_membership_manager', !empty($applicationID) ? ['membership_application' => $applicationID] : []);
    }
}