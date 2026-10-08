<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\Application;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityStorageBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\ConditionInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Entity\EnumBackedEntityInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionException;
use ReflectionProperty;

/**
 * Unit tests for ApplicationStorage.
 *
 * @covers       \Drupal\esn_membership_manager\Entity\Application\ApplicationStorage
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @uses         \Drupal\esn_membership_manager\Utility\ApprovalStatuses
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApplicationStorageTest extends MembershipManagerTestCase
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
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doCreate'])
            ->getMock();

        $entityType = $this->createMock(ContentEntityTypeInterface::class);
        $entityType->method('getClass')->willReturn(Application::class);
        $entityType->method('getKey')->willReturn(false);

        $bundleInfo = $this->createMock(EntityTypeBundleInfoInterface::class);
        $bundleInfo->method('getBundleInfo')->willReturn([]);

        $bundleInfoProp = new ReflectionProperty(ContentEntityStorageBase::class, 'entityTypeBundleInfo');
        $bundleInfoProp->setValue($storage, $bundleInfo);

        $baseEntityClassProp = new ReflectionProperty(EntityStorageBase::class, 'baseEntityClass');
        $baseEntityClassProp->setValue($storage, Application::class);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $entityTypeIdProp = new ReflectionProperty($storage, 'entityTypeId');
        $entityTypeIdProp->setValue($storage, 'membership_application');

        $application = $this->createMock(ApplicationInterface::class);
        $storage->method('doCreate')->willReturn($application);

        $result = $storage->create(['name' => 'Alice']);
        $this->assertSame($application, $result);

        $otherEntity = $this->createMock(ContentEntityInterface::class);
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doCreate'])
            ->getMock();
        $baseEntityClassProp->setValue($storage2, Application::class);
        $bundleInfoProp->setValue($storage2, $bundleInfo);
        $entityTypeProp->setValue($storage2, $entityType);
        $entityTypeIdProp->setValue($storage2, 'membership_application');
        $storage2->method('doCreate')->willReturn($otherEntity);

        $this->assertNull($storage2->create(['name' => 'Other']));
    }

    /**
     * @throws ReflectionException
     */
    public function testLoad(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doLoadMultiple', 'postLoad'])
            ->getMock();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('isStaticallyCacheable')->willReturn(false);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->willReturnCallback(function (array $ids) use ($application, $otherEntity) {
            $id = reset($ids);
            if ((string)$id === '1') {
                return ['1' => $application];
            }
            if ((string)$id === '2') {
                return ['2' => $otherEntity];
            }
            return [];
        });

        $this->assertSame($application, $storage->load('1'));
        $this->assertNull($storage->load('2'));
        $this->assertNull($storage->load('3'));
    }

    /**
     * @throws ReflectionException
     */
    public function testLoadMultiple(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['doLoadMultiple', 'postLoad'])
            ->getMock();

        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('isStaticallyCacheable')->willReturn(false);

        $entityTypeProp = new ReflectionProperty($storage, 'entityType');
        $entityTypeProp->setValue($storage, $entityType);

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->with(['1', '2'])->willReturn([
            '1' => $application,
            '2' => $otherEntity,
        ]);

        $result = $storage->loadMultiple(['1', '2']);
        $this->assertCount(1, $result);
        $this->assertSame($application, $result['1']);
    }

    /**
     * @throws ReflectionException
     */
    public function testLoadByProperties(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
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

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('1');

        $otherEntity = $this->createMock(EntityInterface::class);
        $otherEntity->method('id')->willReturn('2');

        $storage->method('doLoadMultiple')->with(['1', '2'])->willReturn([
            '1' => $application,
            '2' => $otherEntity,
        ]);

        $result = $storage->loadByProperties(['name' => 'Alice']);
        $this->assertCount(1, $result);
        $this->assertSame($application, $result['1']);
    }

    public function testGetByIdentifier(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByESNcard', 'getByPassToken'])
            ->getMock();

        $cardApp = $this->createMock(ApplicationInterface::class);
        $passApp = $this->createMock(ApplicationInterface::class);

        $cardNumber = '1234567CYPA';
        $passToken = '0123456789ABCDEF0123456789ABCDEF';

        $storage->method('getByESNcard')->with($cardNumber)->willReturn($cardApp);
        $storage->method('getByPassToken')->with($passToken)->willReturn($passApp);

        $this->assertSame($cardApp, $storage->getByIdentifier($cardNumber));
        $this->assertSame($passApp, $storage->getByIdentifier($passToken));
        $this->assertNull($storage->getByIdentifier('invalid-identifier'));
    }

    public function testGetByESNcard(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByUniqueField'])
            ->getMock();

        $app = $this->createMock(ApplicationInterface::class);
        $other = $this->createMock(EnumBackedEntityInterface::class);

        $storage->method('getByUniqueField')->willReturnMap([
            [ApplicationField::ESNcardNumber, '1234567CYPA', $app],
            [ApplicationField::ESNcardNumber, 'other', $other],
            [ApplicationField::ESNcardNumber, 'none', null],
        ]);

        $this->assertSame($app, $storage->getByESNcard('1234567CYPA'));
        $this->assertNull($storage->getByESNcard('other'));
        $this->assertNull($storage->getByESNcard('none'));
    }

    public function testGetByPassToken(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByUniqueField'])
            ->getMock();

        $app = $this->createMock(ApplicationInterface::class);
        $other = $this->createMock(EnumBackedEntityInterface::class);

        $storage->method('getByUniqueField')->willReturnMap([
            [ApplicationField::PassToken, 'TOKEN123', $app],
            [ApplicationField::PassToken, 'other', $other],
            [ApplicationField::PassToken, 'none', null],
        ]);

        $this->assertSame($app, $storage->getByPassToken('TOKEN123'));
        $this->assertNull($storage->getByPassToken('other'));
        $this->assertNull($storage->getByPassToken('none'));
    }

    public function testGetByPaymentLinkID(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByUniqueField'])
            ->getMock();

        $app = $this->createMock(ApplicationInterface::class);
        $other = $this->createMock(EnumBackedEntityInterface::class);

        $storage->method('getByUniqueField')->willReturnMap([
            [ApplicationField::PaymentLinkID, 'plink_123', $app],
            [ApplicationField::PaymentLinkID, 'other', $other],
            [ApplicationField::PaymentLinkID, 'none', null],
        ]);

        $this->assertSame($app, $storage->getByPaymentLinkID('plink_123'));
        $this->assertNull($storage->getByPaymentLinkID('other'));
        $this->assertNull($storage->getByPaymentLinkID('none'));
    }

    public function testGetByEmailAddress(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getByUniqueField'])
            ->getMock();

        $app = $this->createMock(ApplicationInterface::class);
        $other = $this->createMock(EnumBackedEntityInterface::class);

        $storage->method('getByUniqueField')->willReturnMap([
            [ApplicationField::Email, 'test@example.com', $app],
            [ApplicationField::Email, 'other@example.com', $other],
            [ApplicationField::Email, 'none@example.com', null],
        ]);

        $this->assertSame($app, $storage->getByEmailAddress('test@example.com'));
        $this->assertNull($storage->getByEmailAddress('other@example.com'));
        $this->assertNull($storage->getByEmailAddress('none@example.com'));
    }

    public function testCountByEmail(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['countByField'])
            ->getMock();

        $storage->expects($this->once())
            ->method('countByField')
            ->with(ApplicationField::Email, 'test@example.com')
            ->willReturn(5);

        $this->assertEquals(5, $storage->countByEmail('test@example.com'));
    }

    public function testCountBacklogged(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(12);
        $storage->method('getQuery')->willReturn($query);

        $this->assertEquals(12, $storage->countBacklogged());
    }

    public function testSearchEmptyResults(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query);

        $result = $storage->search('', null, null, null, 'ASC', 'created');
        $this->assertEquals([], $result);
    }

    public function testSearchWithSearchStatusBlacklistedAndESNcard(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1', '2']);
        $storage->method('getQuery')->willReturn($query);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage->method('loadMultiple')->with(['1', '2'])->willReturn([
            '1' => $app,
            '2' => $nonApp,
        ]);

        $result = $storage->search('alice', ApprovalStatuses::Blacklisted, '1', null, 'ASC', 'date_paid');
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['1']);
    }

    public function testSearchStatusPendingAndPass(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1']);
        $storage->method('getQuery')->willReturn($query);

        $app = $this->createMock(ApplicationInterface::class);
        $storage->method('loadMultiple')->with(['1'])->willReturn(['1' => $app]);

        $result = $storage->search('', ApprovalStatuses::Pending, null, '1', 'DESC', 'esncard_number');
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['1']);
    }

    public function testSearchStatusPositiveApprovedAndDefaultSort(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1']);
        $storage->method('getQuery')->willReturn($query);

        $app = $this->createMock(ApplicationInterface::class);
        $storage->method('loadMultiple')->with(['1'])->willReturn(['1' => $app]);

        $result = $storage->search('', ApprovalStatuses::Approved, null, null, 'DESC', 'unknown_sort');
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['1']);
    }

    public function testSearchStatusPositiveDeliveredHighestIndex(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query = $this->createQueryMock();
        $query->method('execute')->willReturn(['1']);
        $storage->method('getQuery')->willReturn($query);

        $app = $this->createMock(ApplicationInterface::class);
        $storage->method('loadMultiple')->with(['1'])->willReturn(['1' => $app]);

        $result = $storage->search('', ApprovalStatuses::Delivered, null, null, 'ASC', 'created');
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['1']);
    }

    public function testGetUnproducedESNcards(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        // Empty case
        $query1 = $this->createQueryMock();
        $query1->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query1);

        $this->assertEquals([], $storage->getUnproducedESNcards());

        // Non-empty case with filtering of non-ApplicationInterface
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(['1', '2']);
        $storage2->method('getQuery')->willReturn($query2);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage2->method('loadMultiple')->with(['1', '2'])->willReturn([
            '1' => $app,
            '2' => $nonApp,
        ]);

        $result = $storage2->getUnproducedESNcards();
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['1']);
    }

    public function testGetSelectedESNcards(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        // Empty case
        $query1 = $this->createQueryMock();
        $query1->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query1);

        $this->assertEquals([], $storage->getSelectedESNcards([1, 2]));

        // Non-empty case with filtering of non-ApplicationInterface
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(['10', '20']);
        $storage2->method('getQuery')->willReturn($query2);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage2->method('loadMultiple')->with(['10', '20'])->willReturn([
            '10' => $app,
            '20' => $nonApp,
        ]);

        $result = $storage2->getSelectedESNcards([10, 20]);
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['10']);
    }

    public function testGetBacklogged(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        // Empty case
        $query1 = $this->createQueryMock();
        $query1->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query1);

        $this->assertEquals([], $storage->getBacklogged());

        // Non-empty case
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(['5', '6']);
        $storage2->method('getQuery')->willReturn($query2);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage2->method('loadMultiple')->with(['5', '6'])->willReturn([
            '5' => $app,
            '6' => $nonApp,
        ]);

        $result = $storage2->getBacklogged();
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['5']);
    }

    public function testGet2WeekDeletions(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        // Empty case
        $query1 = $this->createQueryMock();
        $query1->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query1);

        $this->assertEquals([], $storage->get2WeekDeletions());

        // Non-empty case
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(['7', '8']);
        $storage2->method('getQuery')->willReturn($query2);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage2->method('loadMultiple')->with(['7', '8'])->willReturn([
            '7' => $app,
            '8' => $nonApp,
        ]);

        $result = $storage2->get2WeekDeletions();
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['7']);
    }

    public function testGet1YearDeletions(): void
    {
        $storage = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        // Empty case
        $query1 = $this->createQueryMock();
        $query1->method('execute')->willReturn([]);
        $storage->method('getQuery')->willReturn($query1);

        $this->assertEquals([], $storage->get1YearDeletions());

        // Non-empty case
        $storage2 = $this->getMockBuilder(ApplicationStorage::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuery', 'loadMultiple'])
            ->getMock();

        $query2 = $this->createQueryMock();
        $query2->method('execute')->willReturn(['9', '10']);
        $storage2->method('getQuery')->willReturn($query2);

        $app = $this->createMock(ApplicationInterface::class);
        $nonApp = $this->createMock(EntityInterface::class);

        $storage2->method('loadMultiple')->with(['9', '10'])->willReturn([
            '9' => $app,
            '10' => $nonApp,
        ]);

        $result = $storage2->get1YearDeletions();
        $this->assertCount(1, $result);
        $this->assertSame($app, $result['9']);
    }
}
