<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_membership_manager\Entity\EnumDrivenEntityInterface;
use Drupal\file\FileInterface;

interface ApplicationInterface extends EnumDrivenEntityInterface
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
     * Safely retrieves the fully loaded File entity for the Proof of Status.
     *
     * @return FileInterface|null
     */
    public function getProofDocument(): ?FileInterface;

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