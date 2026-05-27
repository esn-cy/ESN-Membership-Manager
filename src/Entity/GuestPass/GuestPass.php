<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_cyprus_core\Entity\EnumBackedEntityBase;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;

/**
 * @ContentEntityType(
 * id = "membership_guest",
 * label = @Translation("Membership Guest Pass"),
 * base_table = "membership_guest",
 * entity_keys = {
 *  "id" = "id",
 * },
 * handlers = {
 * "storage" = "Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage",
 * "access" = "Drupal\esn_membership_manager\Entity\GuestPass\GuestPassAccess",
 * },
 * fieldable = TRUE,
 * )
 */
class GuestPass extends EnumBackedEntityBase implements GuestPassInterface
{
    protected static function getFieldEnumClass(): string
    {
        return GuestPassField::class;
    }

    /**
     * {@inheritdoc}
     */
    public function getReferer(): ?ApplicationInterface
    {
        $item = $this->get(GuestPassField::RefererID->value);
        $entity = $item->isEmpty() ? null : $item->getEntity();
        if ($entity instanceof ApplicationInterface) {
            return $entity;
        } else {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFullName(): string
    {
        return "{$this->getValue(GuestPassField::Name)} {$this->getValue(GuestPassField::Surname)}";
    }

    /**
     * {@inheritdoc}
     */
    public function getDateCreated(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateCreated);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateApproved(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateApproved);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateRedeemed(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateRedeemed);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateLastModified(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateLastModified);
    }
}