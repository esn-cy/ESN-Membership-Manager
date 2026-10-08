<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\GuestPass;

use DateInterval;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPass;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\omnia\Entity\EnumBackedEntityInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionException;
use ReflectionProperty;

/**
 * Unit tests for GuestPassStorage.
 *
 * @covers       \Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage
 * @uses         \Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class GuestPassStorageTest extends MembershipManagerTestCase
{
    private function createQueryMock(): QueryInterface&MockObject
    {
        $query = $this->createMock(QueryInterface::class);
        $query->method('accessCheck')->willReturnSelf();
        $query->method('condition')->willReturnSelf();
        $query->method('notExists')->willReturnSelf();
        $query->method('exists')->willReturnSelf();
        $query->method('pager')->willReturnSelf();
        $query->method('sort')->willReturnSelf();
        $query->method('count')->willReturnSelf();

        $group = $this->createMock(ConditionInterface::class);
        $group->method('condition')->willReturnSelf();
        $group->method('notExists')->willReturnSelf();
        $group->method('exists')->willReturnSelf();

        $query->method('andConditionGroup')->willReturn($group);
        $query->method('orConditionGroup')->willReturn($group);

        return $query;
    }

    /**
     * @throws ReflectionException
     */
    public function testCreate(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doCreate'])
            ->getMock();

        $entityType = $this->createMock(ContentEntityTypeInterface::class);
        $entityType->method('getClass')->willReturn(GuestPass::class);
        $entityType->method('getKey')->willReturn(false);

        $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
        $bundleInfo->method('getBundleInfo')->willReturn([]);

        $bundleInfoProp = new ReflectionProperty(ContentEntityStorageBase::class, 'entityTypeBundleInfo');
        $bundleInfoProp->setValue($storage, $bundleInfo);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $entityTypeIdProp = new ReflectionProperty($storage, 'entityTypeId');
        $entityTypeIdProp->setValue($storage, 'membership_guest');

        $baseEntityClassProp = new ReflectionProperty(EntityStorageBase::class, 'baseEntityClass');
        $baseEntityClassProp->setValue($storage, GuestPass::class);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $storage->method('doCreate')->willReturn($guestPass);

        $result = $storage->create(['name' => 'John']);
        $this->assertSame($guestPass, $result);

        $otherEntity = $this->createMock(ContentEntityInterface::class);
        $storage2 = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doCreate'])
            ->getMock();
        $baseEntityClassProp->setValue($storage2, GuestPass::class);
        $bundleInfoProp->setValue($storage2, $bundleInfo);
        $entityTypeProp->setValue($storage2, $entityType);
        $entityTypeIdProp->setValue($storage2, 'membership_guest');
        $storage2->method('doCreate')->willReturn($otherEntity);

        $this->assertNull($storage2->create(['name' => 'Other']));
    }

    /**
     * @throws ReflectionException
     */
    public function testLoad(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doLoadMultiple', 'postLoad'])
            ->getMock();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('isStaticallyCacheable')->willReturn(false);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->willReturnCallback(function (array $ids) use ($guestPass, $otherEntity) {
            $id = reset($ids);
            if ((string)$id === '1') {
                return ['1' => $guestPass];
            }
            if ((string)$id === '2') {
                return ['2' => $otherEntity];
            }
            return [];
        });

        $this->assertSame($guestPass, $storage->load('1'));
        $this->assertNull($storage->load('2'));
        $this->assertNull($storage->load('3'));
    }

    /**
     * @throws ReflectionException
     */
    public function testLoadMultiple(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doLoadMultiple', 'postLoad'])
            ->getMock();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('isStaticallyCacheable')->willReturn(false);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->with(['1', '2'])->willReturn([
            '1' => $guestPass,
            '2' => $otherEntity,
        ]);

        $result = $storage->loadMultiple(['1', '2']);
        $this->assertCount(1, $result);
        $this->assertSame($guestPass, $result['1']);
    }

    /**
     * @throws ReflectionException
     */
    public function testLoadByProperties(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'buildPropertyQuery', 'doLoadMultiple', 'postLoad'])
            ->getMock();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('isStaticallyCacheable')->willReturn(false);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1', '2']);
        $storage->method('getQuery')->willReturn($query);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->with(['1', '2'])->willReturn([
            '1' => $guestPass,
            '2' => $otherEntity,
        ]);

        $result = $storage->loadByProperties(['name' => 'John']);
        $this->assertCount(1, $result);
        $this->assertSame($guestPass, $result['1']);
    }

    public function testGetByPassToken(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByUniqueField'])
            ->getMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $otherEntity = $this->createMock(EnumBackedEntityInterface::class);

        $storage->method('getByUniqueField')->willReturnMap([
            [GuestPassField::PassToken, 'token_valid', $guestPass],
            [GuestPassField::PassToken, 'token_other', $otherEntity],
            [GuestPassField::PassToken, 'token_none', null],
        ]);

        $this->assertSame($guestPass, $storage->getByPassToken('token_valid'));
        $this->assertNull($storage->getByPassToken('token_other'));
        $this->assertNull($storage->getByPassToken('token_none'));
    }

    public function testGetByReferrerID(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['loadByProperties'])
            ->getMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $storage->expects($this->once())
            ->method('loadByProperties')
            ->with([GuestPassField::RefererID->value => 42])
            ->willReturn([$guestPass]);

        $result = $storage->getByReferrerID(42);
        $this->assertCount(1, $result);
        $this->assertSame($guestPass, $result[0]);
    }

    public function testGetActiveByReferrerID(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByReferrerID'])
            ->getMock();

        $pending = $this->createMock(GuestPassInterface::class);
        $pending->method('getApprovalStatus')->willReturn('Pending');

        $approved = $this->createMock(GuestPassInterface::class);
        $approved->method('getApprovalStatus')->willReturn('Approved');

        $expired = $this->createMock(GuestPassInterface::class);
        $expired->method('getApprovalStatus')->willReturn('Expired');

        $redeemed = $this->createMock(GuestPassInterface::class);
        $redeemed->method('getApprovalStatus')->willReturn('Redeemed');

        $storage->method('getByReferrerID')->with(99)->willReturn([
            $pending,
            $approved,
            $expired,
            $redeemed,
        ]);

        $result = $storage->getActiveByReferrerID(99);
        $this->assertCount(2, $result);
        $this->assertContains($pending, $result);
        $this->assertContains($approved, $result);
        $this->assertNotContains($expired, $result);
        $this->assertNotContains($redeemed, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetActiveEmpty(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query);

        $result = $storage->getActive();
        $this->assertEquals([], $result);
    }

    /**
     * @throws Exception
     */
    public function testGetActiveWithPasses(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1', '2', '3', '4']);
        $storage->method('getQuery')->willReturn($query);

        $pending = $this->createMock(GuestPassInterface::class);
        $pending->method('getApprovalStatus')->with('P7D')->willReturn('Pending');

        $approved = $this->createMock(GuestPassInterface::class);
        $approved->method('getApprovalStatus')->with('P7D')->willReturn('Approved');

        $expired = $this->createMock(GuestPassInterface::class);
        $expired->method('getApprovalStatus')->with('P7D')->willReturn('Expired');

        $redeemed = $this->createMock(GuestPassInterface::class);
        $redeemed->method('getApprovalStatus')->with('P7D')->willReturn('Redeemed');

        $storage->method('loadMultiple')->with(['1', '2', '3', '4'])->willReturn([
            '1' => $pending,
            '2' => $approved,
            '3' => $expired,
            '4' => $redeemed,
        ]);

        $result = $storage->getActive();
        $this->assertCount(2, $result);
        $this->assertContains($pending, $result);
        $this->assertContains($approved, $result);
    }

    public function testCountDuplicates(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(3);
        $storage->method('getQuery')->willReturn($query);

        $result = $storage->countDuplicates('John', 'Doe', 'john@example.com');
        $this->assertEquals(3, $result);

        // Test with null values
        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(0);
        $storage2 = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();
        $storage2->method('getQuery')->willReturn($query2);

        $result2 = $storage2->countDuplicates(null, null, null);
        $this->assertEquals(0, $result2);
    }

    public function testSearchEmptyResults(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query);

        $result = $storage->search('', '', 'ASC', 'created');
        $this->assertEquals([], $result);
    }

    public function testSearchWithSearchAndPendingStatusAndCreatedSort(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1']);
        $storage->method('getQuery')->willReturn($query);

        $pass = $this->createMock(GuestPassInterface::class);
        $storage->method('loadMultiple')->with(['1'])->willReturn([$pass]);

        $result = $storage->search('john', 'Pending', 'ASC', 'created');
        $this->assertCount(1, $result);
        $this->assertSame($pass, $result[0]);
    }

    public function testSearchApprovedStatusFiltersByDate(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1', '2']);
        $storage->method('getQuery')->willReturn($query);

        $recentDate = new DrupalDateTime();
        $oldDate = (new DrupalDateTime())->sub(new DateInterval('P10D'));

        $recentPass = $this->createMock(GuestPassInterface::class);
        $recentPass->method('getDateApproved')->willReturn($recentDate);

        $oldPass = $this->createMock(GuestPassInterface::class);
        $oldPass->method('getDateApproved')->willReturn($oldDate);

        $storage->method('loadMultiple')->with(['1', '2'])->willReturn([
            'recent' => $recentPass,
            'old' => $oldPass,
        ]);

        $result = $storage->search('', 'Approved', 'DESC', 'approved');
        $this->assertCount(1, $result);
        $this->assertSame($recentPass, $result['recent']);
    }

    public function testSearchExpiredStatusFiltersByDate(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1', '2']);
        $storage->method('getQuery')->willReturn($query);

        $recentDate = new DrupalDateTime();
        $oldDate = (new DrupalDateTime())->sub(new DateInterval('P10D'));

        $recentPass = $this->createMock(GuestPassInterface::class);
        $recentPass->method('getDateApproved')->willReturn($recentDate);

        $oldPass = $this->createMock(GuestPassInterface::class);
        $oldPass->method('getDateApproved')->willReturn($oldDate);

        $storage->method('loadMultiple')->with(['1', '2'])->willReturn([
            'recent' => $recentPass,
            'old' => $oldPass,
        ]);

        $result = $storage->search('', 'Expired', 'DESC', 'redeemed');
        $this->assertCount(1, $result);
        $this->assertSame($oldPass, $result['old']);
    }

    public function testSearchRedeemedStatusAndDefaultSort(): void
    {
        $storage = $this->getMockBuilder(GuestPassStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1']);
        $storage->method('getQuery')->willReturn($query);

        $pass = $this->createMock(GuestPassInterface::class);
        $storage->method('loadMultiple')->with(['1'])->willReturn([$pass]);

        $result = $storage->search('', 'Redeemed', 'DESC', 'unknown_sort');
        $this->assertCount(1, $result);
        $this->assertSame($pass, $result[0]);
    }
}
