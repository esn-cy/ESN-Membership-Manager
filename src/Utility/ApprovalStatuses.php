<?php

namespace Drupal\esn_membership_manager\Utility;

use Drupal\esn_membership_manager\Object\Status;

class ApprovalStatuses
{
    public const Pending = 'Pending';
    public const Approved = 'Approved';
    public const Rejected = 'Rejected';
    public const Paid = 'Paid';
    public const Issued = 'Issued';
    public const Delivered = 'Delivered';
    public const Blacklisted = 'Blacklisted';

    public const PositiveStatuses = [
        self::Approved,
        self::Paid,
        self::Issued,
        self::Delivered,
    ];

    public const PaidStatuses = [
        self::Paid,
        self::Issued,
        self::Delivered,
    ];

    public const NegativeStatuses = [
        self::Rejected,
    ];

    public const PendingStatuses = [
        self::Pending,
    ];

    public const AuxiliaryStatuses = [
        self::Blacklisted,
    ];

    /**
     * @return Status[]
     */
    public static function getStatuses(string $status): array
    {
        $statuses = [];

        $reasonsSplit = str_contains($status, '/') ? explode('/', $status) : [$status];
        foreach ($reasonsSplit as $reason) {
            $reasonParts = explode('-', $reason);
            $statuses[] = new Status(
                $reasonParts[0],
                $reasonParts[1] ?? '',
                $reasonParts[2] ?? ''
            );
        }
        return $statuses;
    }

    public static function getDominantStatus(string $rawStatus): Status
    {
        if (!str_contains($rawStatus, '/')) {
            preg_match('/^([a-zA-Z]+)/', $rawStatus, $matches);
            return new Status($matches[1] ?? $rawStatus);
        }

        $statuses = self::getStatuses($rawStatus);

        $hasPending = false;
        $positiveIndex = -1;
        $hasBlacklisted = false;

        foreach ($statuses as $status) {
            if (in_array($status->status, self::NegativeStatuses)) {
                return $status->clearIssue();
            }
            if ($status->status == self::Blacklisted) {
                $hasBlacklisted = true;
                continue;
            }
            if (in_array($status->status, self::PendingStatuses)) {
                $hasPending = true;
                continue;
            }
            $index = array_search($status->status, self::PositiveStatuses);
            if ($index !== false && $index > $positiveIndex) {
                $positiveIndex = $index;
            }
        }

        if ($hasBlacklisted) {
            return new Status(self::Blacklisted);
        }
        if ($hasPending) {
            return new Status(self::Pending);
        }
        if ($positiveIndex > -1) {
            return new Status(self::PositiveStatuses[$positiveIndex]);
        }
        return self::getDominantStatus($statuses[0]->status);
    }

    private static function checkApprovalStatusIssue(string $currentDominant, string $newStatus, array $categoryStatuses): ?string
    {
        if ($newStatus === $categoryStatuses[0]) {
            if (!in_array($currentDominant, self::PendingStatuses)) {
                return 'This application already been approved or rejected.';
            }
        } else {
            for ($i = 1; $i < count($categoryStatuses) - 1; $i++) {
                if ($categoryStatuses[$i] === $newStatus) {
                    if ($currentDominant !== $categoryStatuses[$i - 1]) {
                        return 'This status has been applied out of order.';
                    } else {
                        break;
                    }
                }
            }
        }
        return null;
    }

    public static function canAddStatus(string $currentRawStatus, string $newStatus): ?string
    {
        $currentDominant = self::getDominantStatus($currentRawStatus);

        if (in_array($newStatus, self::PositiveStatuses)) {
            $issue = self::checkApprovalStatusIssue($currentDominant->status, $newStatus, self::PositiveStatuses);
            if (!empty($issue)) {
                return $issue;
            }
        } elseif (in_array($newStatus, self::NegativeStatuses)) {
            $issue = self::checkApprovalStatusIssue($currentDominant->status, $newStatus, self::NegativeStatuses);
            if (!empty($issue)) {
                return $issue;
            }
        } elseif (in_array($newStatus, self::AuxiliaryStatuses)) {
            if (in_array($currentDominant->status, self::PendingStatuses)) {
                return 'This status cannot be applied to a pending application.';
            }
        }
        return null;
    }

    public static function addStatus(string $currentRawStatus, string $newStatus): string
    {
        return "$currentRawStatus/$newStatus";
    }

    public static function removeStatus(string $currentRawStatus, string $statusToRemove): string
    {
        $newStatus = str_replace($statusToRemove, '', $currentRawStatus);
        return trim(str_replace('//', '/', $newStatus), "/");
    }
}