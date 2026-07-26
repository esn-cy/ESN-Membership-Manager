<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\omnia\Entity\EnumBackedEntityStorage;

class GuestPassStorage extends EnumBackedEntityStorage
{
    public function load($id): ?GuestPassInterface
    {
        $entity = parent::load($id);

        return $entity instanceof GuestPassInterface ? $entity : null;
    }

    /**
     * @return GuestPassInterface[]
     */
    public function loadByProperties(array $values = []): array
    {
        $entities = parent::loadMultiple($values);

        return array_filter($entities, fn($entity) => $entity instanceof GuestPassInterface);
    }

    /**
     * @return GuestPassInterface[]
     */
    public function loadMultiple(?array $ids = null): array
    {
        $entities = parent::loadMultiple($ids);

        return array_filter($entities, fn($entity) => $entity instanceof GuestPassInterface);
    }

    public function getByPassToken($passToken): ?GuestPassInterface
    {
        $application = $this->getByUniqueField(GuestPassField::PassToken, $passToken);
        return $application instanceof GuestPassInterface ? $application : null;
    }
}