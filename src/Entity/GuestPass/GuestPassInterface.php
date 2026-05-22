<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\EnumDrivenEntityInterface;

interface GuestPassInterface extends EnumDrivenEntityInterface
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