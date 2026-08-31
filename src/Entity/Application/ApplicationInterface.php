<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_membership_manager\Object\Status;
use Drupal\file\FileInterface;
use Drupal\omnia\Entity\EnumBackedEntityInterface;

interface ApplicationInterface extends EnumBackedEntityInterface
{
    /**
     * Updates the last scanned field to now.
     *
     * @return $this
     */
    public function updateLastScanned(): self;

    /**
     * Retrieves the full name of the applicant.
     * @return string The full name.
     */
    public function getFullName(): string;

    /**
     * Retrieves the approval status of the application.
     * @return string The approval status.
     */
    public function getApprovalStatus(): string;

    /**
     * Adds an approval status to the application.
     *
     * Validates if the status can be added sequentially based on the current dominant status.
     *
     * @param string $status The status to add (e.g., ApprovalStatuses::Approved or a serialized Status object).
     * @return bool|string True if the status was successfully added, or a string describing the validation issue if it
     * cannot be applied.
     */
    public function addApprovalStatus(string $status): bool|string;

    /**
     * Removes a specific approval status from the application's status history.
     *
     * @param string $status The exact status string to remove.
     */
    public function removeApprovalStatus(string $status): void;

    /**
     * Retrieves the all the statuses with the reason format of an application as an array containing the category and issue.
     * @return ?Status[] The rejection reasons, null if the application wasn't rejected.
     */
    public function getAllReasons(): ?array;

    /**
     * Retrieves the remediated rejection reasons of an application as an array containing the category and issue.
     * @return ?Status[] The rejection reasons, null if the application wasn't rejected.
     */
    public function getPendingReasons(): ?array;

    /**
     * Clears all pending statuses and reasons in preparation for a positive or negative status.
     */
    public function clearPendingStatuses(): void;

    /**
     * Retrieves the rejection reasons of an application as an array containing the category and issue.
     * @return ?Status[] The rejection reasons, null if the application wasn't rejected.
     */
    public function getRejectionReasons(): ?array;

    /**
     * Checks if the application has been Approved or has another positive status.
     *
     * @return bool True if the application is approved, paid, issued, or delivered.
     */
    public function isApproved(): bool;

    /**
     * Checks if the application has been Paid status or has a subsequent positive status.
     *
     * @return bool True if the application is paid, issued, or delivered.
     */
    public function isPaid(): bool;

    /**
     * Checks if the application has been rejected.
     *
     * @return bool True if the dominant status of the application is rejected.
     */
    public function isRejected(): bool;

    /**
     * Checks if the application is pending review.
     *
     * @return bool True if the dominant status of the application is pending.
     */
    public function isPending(): bool;

    /**
     * Checks if the application has been blacklisted.
     *
     * @return bool True if the dominant status of the application is blacklisted.
     */
    public function isBlacklisted(): bool;

    /**
     * Safely retrieves the fully loaded File entity for the Proof of Status.
     *
     * @return FileInterface|null
     */
    public function getStatusDocument(): ?FileInterface;

    /**
     * Safely retrieves the fully loaded File entity for the ID Document.
     *
     * @return FileInterface|null
     */
    public function getIDDocument(): ?FileInterface;

    /**
     * Safely retrieves the fully loaded File entity for the Face Photo.
     *
     * @return FileInterface|null
     */
    public function getFacePhoto(): ?FileInterface;

    /**
     * Retrieves the date of birth of the applicant.
     *
     * @return DrupalDateTime|null The date of birth.
     */
    public function getDateOfBirth(): ?DrupalDateTime;

    /**
     * Retrieves the date the application was created.
     *
     * @return DrupalDateTime|null The creation date.
     */
    public function getDateCreated(): ?DrupalDateTime;

    /**
     * Retrieves the date the application was approved.
     *
     * @return DrupalDateTime|null The approval date.
     */
    public function getDateApproved(): ?DrupalDateTime;

    /**
     * Retrieves the date the application was marked as paid.
     *
     * @return DrupalDateTime|null The payment date.
     */
    public function getDatePaid(): ?DrupalDateTime;

    /**
     * Retrieves the date the application was last scanned.
     *
     * @return DrupalDateTime|null The last scanned date.
     */
    public function getDateLastScanned(): ?DrupalDateTime;

    /**
     * Retrieves the date the application was last modified.
     *
     * @return DrupalDateTime|null The last modification date.
     */
    public function getDateLastModified(): ?DrupalDateTime;
}