<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\Application;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationAccess;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for ApplicationAccess handler.
 *
 * @covers       \Drupal\esn_membership_manager\Entity\Application\ApplicationAccess
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApplicationAccessTest extends MembershipManagerTestCase
{
    private ApplicationAccess $accessHandler;
    private EntityInterface&MockObject $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('id')->willReturn('application');
        $this->accessHandler = new ApplicationAccess($entityType);

        $language = new Language(['id' => 'en']);
        $this->entity = $this->createMock(EntityInterface::class);
        $this->entity->method('language')->willReturn($language);
        $this->entity->method('getEntityTypeId')->willReturn('application');
        $this->entity->method('id')->willReturn('1');
        $this->entity->method('uuid')->willReturn('uuid-1');
    }

    private function mockAccount(int $id = 1): AccountInterface&MockObject
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn($id);
        return $account;
    }

    public function testViewAccess(): void
    {
        $allowedAccount = $this->mockAccount();
        $allowedAccount->method('hasPermission')->with('view applications')->willReturn(true);

        $result = $this->accessHandler->access($this->entity, 'view', $allowedAccount, true);
        $this->assertInstanceOf(AccessResultAllowed::class, $result);
        $this->assertTrue($result->isAllowed());

        $deniedAccount = $this->mockAccount(2);
        $deniedAccount->method('hasPermission')->with('view applications')->willReturn(false);

        $result = $this->accessHandler->access($this->entity, 'view', $deniedAccount, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testUpdateAccess(): void
    {
        $updatePerms = [
            'edit applications',
            'approve applications',
            'reject applications',
            'mark applications as paid',
            'blacklist applications',
            'issue cards',
            'deliver cards',
            'scan cards',
        ];

        foreach ($updatePerms as $index => $perm) {
            $account = $this->mockAccount($index + 1);
            $account->method('hasPermission')->willReturnCallback(fn($p) => $p === $perm);

            $result = $this->accessHandler->access($this->entity, 'update', $account, true);
            $this->assertTrue($result->isAllowed(), "Permission $perm should allow update access.");
        }

        $deniedAccount = $this->mockAccount(99);
        $deniedAccount->method('hasPermission')->willReturn(false);

        $result = $this->accessHandler->access($this->entity, 'update', $deniedAccount, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testDeleteAccess(): void
    {
        $allowedAccount = $this->mockAccount();
        $allowedAccount->method('hasPermission')->with('delete applications')->willReturn(true);

        $result = $this->accessHandler->access($this->entity, 'delete', $allowedAccount, true);
        $this->assertTrue($result->isAllowed());

        $deniedAccount = $this->mockAccount(2);
        $deniedAccount->method('hasPermission')->with('delete applications')->willReturn(false);

        $result = $this->accessHandler->access($this->entity, 'delete', $deniedAccount, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testDefaultUnsupportedOperationAccess(): void
    {
        $account = $this->mockAccount();
        $result = $this->accessHandler->access($this->entity, 'custom_unknown_op', $account, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testCreateAccess(): void
    {
        $account = $this->mockAccount();
        $result = $this->accessHandler->createAccess(null, $account, [], true);
        $this->assertInstanceOf(AccessResultAllowed::class, $result);
        $this->assertTrue($result->isAllowed());
    }

    public function testFieldAccessWithEditApplicationsPermission(): void
    {
        $account = $this->mockAccount();
        $account->method('hasPermission')->with('edit applications')->willReturn(true);

        $fieldDef = $this->createMock(FieldDefinitionInterface::class);
        $fieldDef->method('getName')->willReturn(ApplicationField::Name->value);

        $result = $this->accessHandler->fieldAccess('edit', $fieldDef, $account, null, true);
        $this->assertInstanceOf(AccessResultAllowed::class, $result);
        $this->assertTrue($result->isAllowed());
    }

    public function testFieldAccessWithSpecificFieldPermission(): void
    {
        $account = $this->mockAccount();
        $account->method('hasPermission')->willReturnCallback(function ($perm) {
            return $perm === 'approve applications';
        });

        $fieldDef = $this->createMock(FieldDefinitionInterface::class);
        $fieldDef->method('getName')->willReturn(ApplicationField::ApprovalStatus->value);

        $result = $this->accessHandler->fieldAccess('edit', $fieldDef, $account, null, true);
        $this->assertTrue($result->isAllowed());

        // Test without any matching permissions
        $deniedAccount = $this->mockAccount(2);
        $deniedAccount->method('hasPermission')->willReturn(false);

        $deniedResult = $this->accessHandler->fieldAccess('edit', $fieldDef, $deniedAccount, null, true);
        $this->assertFalse($deniedResult->isAllowed());
    }

    public function testFieldAccessWithUnknownFieldFallsBackToParent(): void
    {
        $account = $this->mockAccount();
        $account->method('hasPermission')->with('edit applications')->willReturn(false);

        $fieldDef = $this->createMock(FieldDefinitionInterface::class);
        $fieldDef->method('getName')->willReturn('non_existent_field_name');

        $result = $this->accessHandler->fieldAccess('edit', $fieldDef, $account, null, true);
        $this->assertTrue($result->isAllowed());
    }

    public function testFieldAccessViewOperationFallsBackToParent(): void
    {
        $account = $this->mockAccount();

        $fieldDef = $this->createMock(FieldDefinitionInterface::class);
        $fieldDef->method('getName')->willReturn(ApplicationField::Name->value);

        $result = $this->accessHandler->fieldAccess('view', $fieldDef, $account, null, true);
        $this->assertTrue($result->isAllowed());
    }
}
