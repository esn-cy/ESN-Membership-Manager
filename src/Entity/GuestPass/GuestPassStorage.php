<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use DateInterval;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\omnia\Entity\EnumBackedEntityStorage;
use Exception;

class GuestPassStorage extends EnumBackedEntityStorage
{
    public function create(array $values = []): ?GuestPassInterface
    {
        $entity = parent::create($values);

        return $entity instanceof GuestPassInterface ? $entity : null;
    }

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
        $entities = parent::loadByProperties($values);

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

    public function getByPassToken(string $passToken): ?GuestPassInterface
    {
        $application = $this->getByUniqueField(GuestPassField::PassToken, $passToken);
        return $application instanceof GuestPassInterface ? $application : null;
    }

    /**
     * @param string|int $referrerID
     * @return GuestPassInterface[]
     */
    public function getByReferrerID(string|int $referrerID): array
    {
        return $this->loadByProperties([GuestPassField::RefererID->value => $referrerID]);
    }

    /**
     * @return GuestPassInterface[]
     * @throws Exception
     */
    public function getActive(string $interval = 'P7D'): array
    {
        $query = $this->getQuery()
            ->accessCheck(FALSE)
            ->notExists(GuestPassField::DateRedeemed->value);
        $guestPassIDs = $query->execute();

        $guestPasses = $guestPassIDs ? $this->loadMultiple($guestPassIDs) : [];
        if (empty($guestPasses)) {
            return [];
        }

        $activePasses = [];
        foreach ($guestPasses as $guestPass) {
            $status = $guestPass->getApprovalStatus($interval);
            if ($status === 'Pending' || $status === 'Approved') {
                $activePasses[] = $guestPass;
            }
        }

        return $activePasses;
    }

    /**
     * @param string|int $referrerID
     * @return GuestPassInterface[]
     */
    public function getActiveByReferrerID(string|int $referrerID): array
    {
        return array_filter($this->getByReferrerID($referrerID), function (GuestPassInterface $guestPass) {
            /** @noinspection PhpUnhandledExceptionInspection */
            $status = $guestPass->getApprovalStatus();
            return $status === 'Pending' || $status === 'Approved';
        });
    }

    public function countDuplicates(?string $name, ?string $surname, ?string $email): int
    {
        $query = $this->getQuery()
            ->accessCheck(FALSE);

        $nameGroup = $query->andConditionGroup();
        if (!empty($name)) {
            $nameGroup->condition(GuestPassField::Name->value, '%' . $name . '%', 'LIKE');
        }
        if (!empty($surname)) {
            $nameGroup->condition(GuestPassField::Surname->value, '%' . $surname . '%', 'LIKE');
        }

        $queryGroup = $query->orConditionGroup();
        $queryGroup->condition($nameGroup);

        if (!empty($email)) {
            $queryGroup->condition(GuestPassField::Email->value, '%' . $email . '%', 'LIKE');
        }

        $query->condition($queryGroup);
        $query->count();

        return $query->execute();
    }

    /**
     * @return GuestPassInterface[]
     */
    public function search(string $search, string $status, string $sortOrder, string $sortBy): array
    {
        $query = $this->getQuery()
            ->accessCheck(FALSE);

        $andGroup = $query->andConditionGroup();

        if (!empty($search)) {
            $orGroup = $query->orConditionGroup()
                ->condition(GuestPassField::Name->value, $search, 'CONTAINS')
                ->condition(GuestPassField::Surname->value, $search, 'CONTAINS')
                ->condition(GuestPassField::Email->value, $search, 'CONTAINS')
                ->condition(GuestPassField::PassToken->value, $search, 'CONTAINS');
            $andGroup->condition($orGroup);
        }

        if (!empty($status)) {
            switch ($status) {
                case 'Pending':
                    $andGroup
                        ->notExists(GuestPassField::DateApproved->value)
                        ->notExists(GuestPassField::DateRedeemed->value);
                    break;
                case 'Approved':
                case 'Expired':
                    $andGroup
                        ->exists(GuestPassField::DateApproved->value)
                        ->notExists(GuestPassField::DateRedeemed->value);
                    break;
                case 'Redeemed':
                    $andGroup
                        ->exists(GuestPassField::DateRedeemed->value);
                    break;
            }
        }

        if (!empty($search) || !empty($status)) {
            $query->condition($andGroup);
        }

        $query->pager(20);

        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        switch ($sortBy) {
            case 'created':
                $query->sort(GuestPassField::DateCreated->value, $sortOrder);
                break;
            case 'approved':
                $query->sort(GuestPassField::DateApproved->value, $sortOrder);
                break;
            case 'redeemed':
                $query->sort(GuestPassField::DateRedeemed->value, $sortOrder);
                break;
            default:
                $query->sort(GuestPassField::DateCreated->value, 'DESC');
        }

        $guestPassIDs = $query->execute();
        if (empty($guestPassIDs)) {
            return [];
        }

        $expiryThreshold = (new DrupalDateTime())->sub(new DateInterval('P7D'));

        $guestPasses = $this->loadMultiple($guestPassIDs);
        foreach ($guestPasses as $key => $guestPass) {
            if ($status === 'Approved') {
                if ($guestPass->getDateApproved()->getTimestamp() < $expiryThreshold->getTimestamp()) {
                    unset($guestPasses[$key]);
                }
            }
            if ($status === 'Expired') {
                if ($guestPass->getDateApproved()->getTimestamp() > $expiryThreshold->getTimestamp()) {
                    unset($guestPasses[$key]);
                }
            }
        }

        return $guestPasses;
    }
}