<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\omnia\Entity\EnumBackedEntityInterface;
use Exception;

interface GuestPassInterface extends EnumBackedEntityInterface
{
    /**
     * Retrieves the Application entity that referred this guest pass.
     *
     * @return ApplicationInterface|null The referring application, or null if not found.
     */
    public function getReferer(): ?ApplicationInterface;

    /**
     * Retrieves the full name of the guest.
     *
     * @return string The full name.
     */
    public function getFullName(): string;

    /**
     * Retrieves the current approval status of the Guest Pass.
     *
     * The status is determined based on the Guest Pass's lifecycle dates:
     * - 'Redeemed': The pass has already been used/redeemed at an event.
     * - 'Expired': The pass was approved, but the approval date is older than the configured validity interval.
     * - 'Approved': The pass is approved and still within its valid window.
     * - 'Pending': The pass has not yet been approved or redeemed.
     *
     * @param string $interval (optional) The validity period of the pass formatted as a PHP DateInterval string
     * (e.g., 'P7D' for 7 days, 'P1M' for 1 month). Defaults to 'P7D'.
     *
     * @return string The status ('Redeemed', 'Expired', 'Approved', or 'Pending').
     *
     * @throws Exception Thrown if the provided $interval string is not a valid DateInterval format.
     */
    public function getApprovalStatus(string $interval = 'P7D'): string;

    /**
     * Retrieves the date the guest pass was created.
     *
     * @return DrupalDateTime|null The creation date.
     */
    public function getDateCreated(): ?DrupalDateTime;

    /**
     * Retrieves the date the guest pass was approved.
     *
     * @return DrupalDateTime|null The approval date.
     */
    public function getDateApproved(): ?DrupalDateTime;

    /**
     * Retrieves the date the guest pass was redeemed (scanned).
     *
     * @return DrupalDateTime|null The redemption date.
     */
    public function getDateRedeemed(): ?DrupalDateTime;

    /**
     * Retrieves the date the guest pass was last modified.
     *
     * @return DrupalDateTime|null The last modification date.
     */
    public function getDateLastModified(): ?DrupalDateTime;
}