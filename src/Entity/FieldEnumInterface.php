<?php

namespace Drupal\esn_membership_manager\Entity;

use BackedEnum;

interface FieldEnumInterface extends BackedEnum
{
    /**
     * Retrieves the human-readable label for the field.
     *
     * @return string The field label.
     */
    public function label(): string;

    /**
     * Retrieves the Drupal field type (e.g., 'string', 'email', 'datetime').
     *
     * @return string The field type.
     */
    public function type(): string;

    /**
     * Determines whether the field is required.
     *
     * @return bool True if required, false otherwise.
     */
    public function required(): bool;

    /**
     * Determines whether the field must be unique.
     *
     * @return bool True if unique, false otherwise.
     */
    public function unique(): bool;

    /**
     * Retrieves the default value for the field.
     *
     * @return mixed The default value, or null if there is no default.
     */
    public function default(): mixed;

    /**
     * Retrieves additional settings for the field (e.g., max_length).
     *
     * @return array An array of field settings.
     */
    public function settings(): array;
}