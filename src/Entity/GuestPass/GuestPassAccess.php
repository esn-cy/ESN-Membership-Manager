<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

class GuestPassAccess extends EntityAccessControlHandler
{
    protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultReasonInterface|AccessResultNeutral|AccessResult|AccessResultInterface
    {
        return match ($operation) {
            'view' => AccessResult::allowedIfHasPermission($account, 'view applications'),
            'update' => AccessResult::allowedIfHasPermissions($account, ['approve applications', 'decline applications', 'scan cards'], 'OR'),
            'delete' => AccessResult::allowedIfHasPermission($account, 'delete applications'),
            default => AccessResult::neutral(),
        };
    }

    protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultReasonInterface|AccessResult|AccessResultInterface
    {
        return AccessResult::allowed();
    }
}