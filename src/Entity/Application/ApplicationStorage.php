<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use DateInterval;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\esn_cyprus_core\Entity\EnumBackedEntityStorage;

class ApplicationStorage extends EnumBackedEntityStorage
{
    public function load($id): ?ApplicationInterface
    {
        $entity = parent::load($id);

        return $entity instanceof ApplicationInterface ? $entity : null;
    }

    /**
     * @return ApplicationInterface[]
     */
    public function loadByProperties(array $values = []): array
    {
        $entities = parent::loadMultiple($values);

        return array_filter($entities, fn($entity) => $entity instanceof ApplicationInterface);
    }

    /**
     * @return ApplicationInterface[]
     */
    public function loadMultiple(?array $ids = null): array
    {
        $entities = parent::loadMultiple($ids);

        return array_filter($entities, fn($entity) => $entity instanceof ApplicationInterface);
    }

    public function getByIdentifier(string $identifier): ApplicationInterface|null
    {
        $isESNcard = preg_match("/^\d{7}[A-Z]{3}[A-Z0-9]$/", $identifier) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $identifier) == 1;

        if ($isESNcard) {
            return $this->getByESNcard($identifier);
        } elseif ($isPass) {
            return $this->getByPassToken($identifier);
        } else {
            return null;
        }
    }

    public function getByESNcard(string $cardNumber): ApplicationInterface|null
    {
        $application = $this->getByUniqueField(ApplicationField::ESNcardNumber, $cardNumber);
        return $application instanceof ApplicationInterface ? $application : null;
    }

    public function getByPassToken(string $passToken): ApplicationInterface|null
    {
        $application = $this->getByUniqueField(ApplicationField::PassToken, $passToken);
        return $application instanceof ApplicationInterface ? $application : null;
    }

    public function getByPaymentLinkID(string $paymentLinkID): ApplicationInterface|null
    {
        $application = $this->getByUniqueField(ApplicationField::PaymentLinkID, $paymentLinkID);
        return $application instanceof ApplicationInterface ? $application : null;
    }

    public function countByEmail(string $email): int
    {
        return $this->countByField(ApplicationField::Email, $email);
    }

    public function countBacklogged(): int
    {
        return $this->getQuery()
            ->accessCheck(FALSE)
            ->condition(ApplicationField::ESNcardNumber->value, '%BACKLOGGED%', 'LIKE')
            ->count()
            ->execute();
    }

    public function search(string $search, ?string $status, ?string $esncard, ?string $pass, ?string $sortOrder, ?string $sortBy): array
    {
        $query = $this->getQuery()
            ->accessCheck(FALSE);

        $andGroup = $query->andConditionGroup();

        if (!empty($search)) {
            $orGroup = $query->orConditionGroup()
                ->condition(ApplicationField::Name->value, $search, 'CONTAINS')
                ->condition(ApplicationField::Surname->value, $search, 'CONTAINS')
                ->condition(ApplicationField::Email->value, $search, 'CONTAINS')
                ->condition(ApplicationField::ESNcardNumber->value, $search, 'CONTAINS')
                ->condition(ApplicationField::PassToken->value, $search, 'CONTAINS');
            $andGroup->condition($orGroup);
        }

        if (!empty($status)) {
            $andGroup->condition(ApplicationField::ApprovalStatus->value, $status);
        }

        if (!empty($esncard)) {
            $andGroup->condition(ApplicationField::HasESNcard->value, 1);
        } else if (!empty($pass)) {
            $andGroup->condition(ApplicationField::HasESNcard->value, 0);
        }

        if (!empty($search) || !empty($status) || !empty($esncard) || !empty($pass)) {
            $query->condition($andGroup);
        }

        $query->pager(20);

        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        switch ($sortBy) {
            case 'created':
                $query->sort(ApplicationField::DateCreated->value, $sortOrder);
                break;
            case 'date_paid':
                $query->sort(ApplicationField::DatePaid->value, $sortOrder);
                break;
            case 'esncard_number':
                $query->sort(ApplicationField::ESNcardNumber->value, $sortOrder);
                break;
            default:
                $query->sort(ApplicationField::DateCreated->value, 'DESC');
        }

        $applicationIDs = $query->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }

    public function getUnproducedESNcards(): array
    {
        $applicationIDs = $this->getQuery()
            ->accessCheck(FALSE)
            ->condition(ApplicationField::ApprovalStatus->value, 'Paid')
            ->exists(ApplicationField::FacePhotoFileID->value)
            ->sort(ApplicationField::ESNcardNumber->value)
            ->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }

    public function getSelectedESNcards(array $ids): array
    {
        $applicationIDs = $this->getQuery()
            ->condition('id', $ids, 'IN')
            ->accessCheck(FALSE)
            ->exists(ApplicationField::FacePhotoFileID->value)
            ->sort(ApplicationField::ESNcardNumber->value)
            ->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }

    public function getBacklogged(): array
    {
        $applicationIDs = $this->getQuery()
            ->accessCheck(FALSE)
            ->condition(ApplicationField::ESNcardNumber->value, '%BACKLOGGED%', 'LIKE')
            ->sort(ApplicationField::DatePaid->value)
            ->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }

    public function get2WeekDeletions(): array
    {
        $twoWeeksAgo = (new DrupalDateTime())->sub(new DateInterval('P14D'))->format('Y-m-d\TH:i:s');

        $query = $this->getQuery()
            ->accessCheck(FALSE)
            ->condition(ApplicationField::ApprovalStatus->value, 'Pending', '<>');

        $approvalCondition = $query->orConditionGroup()
            ->condition(ApplicationField::DateApproved->value, $twoWeeksAgo, '<')
            ->condition(ApplicationField::ApprovalStatus->value, 'Declined');

        $filesExistCondition = $query->orConditionGroup()
            ->exists(ApplicationField::StatusProofFileID->value)
            ->exists(ApplicationField::IdentityDocumentFileID->value);

        $applicationIDs = $query
            ->condition($filesExistCondition)
            ->condition($approvalCondition)
            ->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }

    public function get1YearDeletions(): array
    {
        $oneYearAgo = (new DrupalDateTime())->sub(new DateInterval('P1Y'))->format('Y-m-d\TH:i:s');

        $query = $this->getQuery()
            ->accessCheck(FALSE);

        $retentionCondition = $query->orConditionGroup();

        $conditionPaid1Y = $query->andConditionGroup()
            ->condition(ApplicationField::HasESNcard->value, 1)
            ->condition(ApplicationField::DatePaid->value, $oneYearAgo, '<');

        $conditionApproved1Y = $query->andConditionGroup()
            ->condition(ApplicationField::HasESNcard->value, 0)
            ->condition(ApplicationField::DateApproved->value, $oneYearAgo, '<');

        $retentionCondition->condition($conditionPaid1Y);
        $retentionCondition->condition($conditionApproved1Y);

        $applicationIDs = $query
            ->condition($retentionCondition)
            ->execute();

        if (empty($applicationIDs)) {
            return [];
        }

        $applications = $this->loadMultiple($applicationIDs);
        foreach ($applications as $key => $application) {
            if (!$application instanceof ApplicationInterface) {
                unset($applications[$key]);
            }
        }

        return $applications;
    }
}