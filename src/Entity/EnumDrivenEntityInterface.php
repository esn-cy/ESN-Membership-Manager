<?php

namespace Drupal\esn_membership_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

interface EnumDrivenEntityInterface extends ContentEntityInterface
{
    /**
     * Generic getter that uses the field Enum.
     * @param FieldEnumInterface $field The field enum case.
     * @return mixed The field value.
     */
    public function getValue(FieldEnumInterface $field): mixed;

    /**
     * Generic setter that uses the field Enum.
     * * @param FieldEnumInterface $field The field enum case.
     * @param mixed $value The value to set.
     * @return $this
     */
    public function setValue(FieldEnumInterface $field, mixed $value): self;

    /**
     * Sets the field value to null.
     *
     * @param FieldEnumInterface $field The field enum case.
     * @return $this
     */
    public function setNull(FieldEnumInterface $field): self;
}