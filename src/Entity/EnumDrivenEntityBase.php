<?php

namespace Drupal\esn_membership_manager\Entity;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\file\FileInterface;

abstract class EnumDrivenEntityBase extends ContentEntityBase implements EnumDrivenEntityInterface
{
    /**
     * {@inheritdoc}
     */
    public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array
    {
        $fields = parent::baseFieldDefinitions($entity_type);

        $enumClass = static::getFieldEnumClass();

        foreach ($enumClass::cases() as $field) {
            $type = $field->type();
            $fields[$field->value] = BaseFieldDefinition::create($type)
                ->setLabel($field->label())
                ->setRequired($field->required());

            if ($field->unique()) {
                $fields[$field->value]->addConstraint('UniqueField', [
                    'message' => "The {$field->label()} must be unique."
                ]);
            }

            if (!empty($field->settings())) {
                $fields[$field->value]->setSettings($field->settings());
            }

            if ($field->default() !== null) {
                $fields[$field->value]->setDefaultValue($field->default());
            }
        }

        return $fields;
    }

    /**
     * @return class-string<FieldEnumInterface>
     */
    abstract protected static function getFieldEnumClass(): string;

    /**
     * {@inheritdoc}
     */
    public function setValue(FieldEnumInterface $field, mixed $value): self
    {
        $this->set($field->value, $value);
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setNull(FieldEnumInterface $field): self
    {
        $this->set($field->value, null);
        return $this;
    }

    /**
     * Helper to safely retrieve a DrupalDateTime object from a datetime field.
     *
     * @param FieldEnumInterface $field The field enum case.
     * @return DrupalDateTime|null The parsed datetime, or null if empty or invalid.
     */
    protected function getDateTimeValue(FieldEnumInterface $field): ?DrupalDateTime
    {
        $dateTime = new DrupalDateTime($this->getValue($field));
        if (!$dateTime->hasErrors()) {
            return $dateTime;
        } else {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getValue(FieldEnumInterface $field): mixed
    {
        $drupalField = $this->get($field->value);

        return match ($field->type()) {
            'entity_reference' => $drupalField->target_id,
            default => $drupalField->value,
        };
    }

    /**
     * Helper to safely retrieve a referenced File entity.
     *
     * @param FieldEnumInterface $field The field enum case.
     * @return FileInterface|null The referenced file entity, or null if not found or empty.
     */
    protected function getFile(FieldEnumInterface $field): ?FileInterface
    {
        $item = $this->get($field->value);
        if ($item->isEmpty()) {
            return null;
        }

        $entity = $item->entity;
        return $entity instanceof FileInterface ? $entity : null;
    }
}