<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\GuestPass;

use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultNeutral;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassAccess;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for GuestPassAccess handler.
 *
 * @covers \Drupal\esn_membership_manager\Entity\GuestPass\GuestPassAccess
 * @group esn_membership_manager
 */
class GuestPassAccessTest extends MembershipManagerTestCase
{
    private GuestPassAccess $accessHandler;
    private EntityInterface&MockObject $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('id')->willReturn('guest_pass');
        $this->accessHandler = new GuestPassAccess($entityType);

        $language = new Language(['id' => 'en']);
        $this->entity = $this->createMock(EntityInterface::class);
        $this->entity->method('language')->willReturn($language);
        $this->entity->method('getEntityTypeId')->willReturn('guest_pass');
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
        $allowedAccount->method('hasPermission')->with('view guest passes')->willReturn(true);

        $result = $this->accessHandler->access($this->entity, 'view', $allowedAccount, true);
        $this->assertInstanceOf(AccessResultAllowed::class, $result);
        $this->assertTrue($result->isAllowed());

        $deniedAccount = $this->mockAccount(2);
        $deniedAccount->method('hasPermission')->with('view guest passes')->willReturn(false);

        $result = $this->accessHandler->access($this->entity, 'view', $deniedAccount, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testUpdateAccess(): void
    {
        foreach (['approve guest passes', 'scan cards'] as $index => $perm) {
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
        $allowedAccount->method('hasPermission')->with('approve guest passes')->willReturn(true);

        $result = $this->accessHandler->access($this->entity, 'delete', $allowedAccount, true);
        $this->assertTrue($result->isAllowed());

        $deniedAccount = $this->mockAccount(2);
        $deniedAccount->method('hasPermission')->with('approve guest passes')->willReturn(false);

        $result = $this->accessHandler->access($this->entity, 'delete', $deniedAccount, true);
        $this->assertInstanceOf(AccessResultNeutral::class, $result);
        $this->assertFalse($result->isAllowed());
    }

    public function testUnsupportedOperationAccess(): void
    {
        $account = $this->mockAccount();
        $result = $this->accessHandler->access($this->entity, 'unknown_op', $account, true);
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
}
