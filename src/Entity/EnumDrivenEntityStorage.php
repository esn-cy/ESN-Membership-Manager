<?php

namespace Drupal\esn_membership_manager\Entity;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;

class EnumDrivenEntityStorage extends SqlContentEntityStorage
{
    public function getByUniqueField(FieldEnumInterface $field, string $value): EnumDrivenEntityInterface|null
    {
        $ids = $this->getQuery()
            ->accessCheck(FALSE)
            ->condition($field->value, $value)
            ->range(0, 1)
            ->execute();

        if (empty($ids)) {
            return null;
        }

        $entity = $this->load(reset($ids));
        if (!$entity instanceof EnumDrivenEntityInterface) {
            return null;
        }
        return $entity;
    }

    public function countByField(FieldEnumInterface $field, string $value): int
    {
        return $this->getQuery()
            ->accessCheck(FALSE)
            ->condition($field->value, $value)
            ->count()
            ->execute();
    }
}