<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;

class ApplicationAccess extends EntityAccessControlHandler
{
    protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResultReasonInterface|AccessResultNeutral|AccessResult|AccessResultInterface
    {
        return match ($operation) {
            'view' => AccessResult::allowedIfHasPermission($account, 'view applications'),
            'update' => AccessResult::allowedIfHasPermissions($account, ['edit applications', 'approve applications', 'reject applications', 'mark applications as paid', 'blacklist applications', 'issue cards', 'deliver cards', 'scan cards'], 'OR'),
            'delete' => AccessResult::allowedIfHasPermission($account, 'delete applications'),
            default => AccessResult::neutral(),
        };
    }

    protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResultReasonInterface|AccessResult|AccessResultInterface
    {
        return AccessResult::allowed();
    }

    protected function checkFieldAccess($operation, FieldDefinitionInterface $field_definition, AccountInterface $account, FieldItemListInterface|null $items = NULL): AccessResultReasonInterface|AccessResult|AccessResultAllowed|AccessResultInterface
    {
        if ($operation == 'edit') {
            if ($account->hasPermission('edit applications')) {
                return AccessResult::allowed();
            }

            $field = ApplicationField::tryFrom($field_definition->getName());
            if ($field) {
                return AccessResult::allowedIfHasPermissions($account, $field->permissions(), 'OR');
            }
        }
        return parent::checkFieldAccess($operation, $field_definition, $account, $items);
    }
}