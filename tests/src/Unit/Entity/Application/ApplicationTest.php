<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\Application;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Object\Status;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Unit tests for Application entity.
 *
 * @covers       \Drupal\esn_membership_manager\Entity\Application\Application
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @uses         \Drupal\esn_membership_manager\Object\Status
 * @uses         \Drupal\esn_membership_manager\Utility\ApprovalStatuses
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApplicationTest extends MembershipManagerTestCase
{
    /**
     * @throws ReflectionException
     */
    public function testGetFieldEnumClass(): void
    {
        $method = new ReflectionMethod(Application::class, 'getFieldEnumClass');
        $this->assertEquals(ApplicationField::class, $method->invoke(null));
    }

    /**
     * @throws Exception
     */
    public function testPostDeleteAllFeaturesEnabled(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'switch_google_wallet' => true,
                'switch_apple_wallet' => true,
            ],
        ]);
        $this->container->set('config.factory', $configFactory);

        $database = $this->createMock(Connection::class);
        $deleteQuery = $this->createMock(Delete::class);
        $deleteQuery->method('condition')->willReturnSelf();
        $deleteQuery->method('execute')->willReturn(1);
        $database->expects($this->exactly(2))
            ->method('delete')
            ->willReturnCallback(function (string $table) use ($deleteQuery) {
                $this->assertContains($table, [
                    'esn_membership_manager_apple_wallet_registrations',
                    'esn_membership_manager_authentication',
                ]);
                return $deleteQuery;
            });
        $this->container->set('database', $database);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Deleted application @id', ['@id' => '42']);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));

        $fileService = $this->createMock(FileService::class);
        $fileService->expects($this->exactly(3))
            ->method('deleteApplicationFile');
        $fileService->expects($this->once())
            ->method('isDirectoryEmpty')
            ->with('membership://42')
            ->willReturn(true);
        $fileService->expects($this->once())
            ->method('deleteDirectory')
            ->with('membership://42');
        $this->container->set('esn_membership_manager.file_service', $fileService);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('disablePaymentLink')
            ->with('plink_123');
        $this->container->set('esn_membership_manager.stripe_service', $stripeService);

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->exactly(2))
            ->method('deleteApplicationObject')
            ->willReturnCallback(function (string $id, string $type) {
                $this->assertEquals('42', $id);
                $this->assertContains($type, ['card', 'pass']);
                return true;
            });
        $this->container->set('esn_membership_manager.google_service', $googleService);

        $storage = $this->createMock(EntityStorageInterface::class);
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('getListCacheTags')->willReturn([]);
        $storage->method('getEntityType')->willReturn($entityType);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinition')->with('membership_application')->willReturn($entityType);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $statusDoc = $this->createMock(FileInterface::class);
        $statusDoc->method('id')->willReturn('101');
        $idDoc = $this->createMock(FileInterface::class);
        $idDoc->method('id')->willReturn('102');
        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('103');

        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id', 'getStatusDocument', 'getIDDocument', 'getFacePhoto', 'getValue'])
            ->getMock();
        $application->method('id')->willReturn('42');
        $application->method('getStatusDocument')->willReturn($statusDoc);
        $application->method('getIDDocument')->willReturn($idDoc);
        $application->method('getFacePhoto')->willReturn($facePhoto);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::PaymentLinkID => 'plink_123',
                ApplicationField::HasESNcard => true,
                ApplicationField::Email => 'john@example.com',
                default => null,
            };
        });

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($application, 'membership_application');

        Application::postDelete($storage, [$application]);
    }

    /**
     * @throws Exception
     */
    public function testPostDeleteDisabledFeaturesAndNoFiles(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'switch_google_wallet' => false,
                'switch_apple_wallet' => false,
            ],
        ]);
        $this->container->set('config.factory', $configFactory);

        $database = $this->createMock(Connection::class);
        $deleteQuery = $this->createMock(Delete::class);
        $deleteQuery->method('condition')->willReturnSelf();
        $deleteQuery->method('execute')->willReturn(1);
        $database->expects($this->once())
            ->method('delete')
            ->with('esn_membership_manager_authentication')
            ->willReturn($deleteQuery);
        $this->container->set('database', $database);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Deleted application @id', ['@id' => '84']);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));

        $fileService = $this->createMock(FileService::class);
        $fileService->expects($this->never())->method('deleteApplicationFile');
        $fileService->expects($this->once())
            ->method('isDirectoryEmpty')
            ->with('membership://84')
            ->willReturn(false);
        $fileService->expects($this->never())->method('deleteDirectory');
        $this->container->set('esn_membership_manager.file_service', $fileService);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->never())->method('disablePaymentLink');
        $this->container->set('esn_membership_manager.stripe_service', $stripeService);

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->never())->method('deleteApplicationObject');
        $this->container->set('esn_membership_manager.google_service', $googleService);

        $storage = $this->createMock(EntityStorageInterface::class);
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('getListCacheTags')->willReturn([]);
        $storage->method('getEntityType')->willReturn($entityType);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinition')->with('membership_application')->willReturn($entityType);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id', 'getStatusDocument', 'getIDDocument', 'getFacePhoto', 'getValue'])
            ->getMock();
        $application->method('id')->willReturn('84');
        $application->method('getStatusDocument')->willReturn(null);
        $application->method('getIDDocument')->willReturn(null);
        $application->method('getFacePhoto')->willReturn(null);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::PaymentLinkID => null,
                ApplicationField::HasESNcard => false,
                ApplicationField::Email => 'jane@example.com',
                default => null,
            };
        });

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($application, 'membership_application');

        Application::postDelete($storage, [$application]);
    }

    /**
     * @throws Exception
     */
    public function testPostDeleteGoogleWalletWithoutESNcard(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'switch_google_wallet' => true,
                'switch_apple_wallet' => false,
            ],
        ]);
        $this->container->set('config.factory', $configFactory);

        $database = $this->createMock(Connection::class);
        $deleteQuery = $this->createMock(Delete::class);
        $deleteQuery->method('condition')->willReturnSelf();
        $deleteQuery->method('execute')->willReturn(1);
        $database->method('delete')->willReturn($deleteQuery);
        $this->container->set('database', $database);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));

        $fileService = $this->createMock(FileService::class);
        $this->container->set('esn_membership_manager.file_service', $fileService);

        $stripeService = $this->createMock(StripeService::class);
        $this->container->set('esn_membership_manager.stripe_service', $stripeService);

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('deleteApplicationObject')
            ->with('99', 'pass')
            ->willReturn(true);
        $this->container->set('esn_membership_manager.google_service', $googleService);

        $storage = $this->createMock(EntityStorageInterface::class);
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('getListCacheTags')->willReturn([]);
        $storage->method('getEntityType')->willReturn($entityType);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinition')->with('membership_application')->willReturn($entityType);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id', 'getStatusDocument', 'getIDDocument', 'getFacePhoto', 'getValue'])
            ->getMock();
        $application->method('id')->willReturn('99');
        $application->method('getStatusDocument')->willReturn(null);
        $application->method('getIDDocument')->willReturn(null);
        $application->method('getFacePhoto')->willReturn(null);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::PaymentLinkID => null,
                ApplicationField::HasESNcard => false,
                ApplicationField::Email => 'test@example.com',
                default => null,
            };
        });

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($application, 'membership_application');

        Application::postDelete($storage, [$application]);
    }

    public function testDocumentGettersWithFiles(): void
    {
        $fileMock = $this->createMock(FileInterface::class);
        $item = new class($fileMock) {
            public mixed $entity;

            public function __construct(mixed $entity)
            {
                $this->entity = $entity;
            }

            public function isEmpty(): bool
            {
                return false;
            }
        };

        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $application->method('get')->willReturn($item);

        $this->assertSame($fileMock, $application->getStatusDocument());
        $this->assertSame($fileMock, $application->getIDDocument());
        $this->assertSame($fileMock, $application->getFacePhoto());
    }

    public function testDocumentGettersEmpty(): void
    {
        $item = $this->createMock(FieldItemListInterface::class);
        $item->method('isEmpty')->willReturn(true);

        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $application->method('get')->willReturn($item);

        $this->assertNull($application->getStatusDocument());
        $this->assertNull($application->getIDDocument());
        $this->assertNull($application->getFacePhoto());
    }

    public function testUpdateLastScanned(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['set'])
            ->getMock();

        $application->expects($this->once())
            ->method('set')
            ->with(
                $this->equalTo(ApplicationField::DateLastScanned->value),
                $this->callback(fn($val) => is_string($val) && strlen($val) > 0)
            )
            ->willReturnSelf();

        $result = $application->updateLastScanned();
        $this->assertSame($application, $result);
    }

    public function testGetFullName(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'Alice',
                ApplicationField::Surname => 'Smith',
                default => null,
            };
        });

        $this->assertEquals('Alice Smith', $application->getFullName());
    }

    public function testGetApprovalStatus(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $application->method('getValue')->with(ApplicationField::ApprovalStatus)->willReturn('Approved');
        $this->assertEquals('Approved', $application->getApprovalStatus());
    }

    public function testAddApprovalStatusWithoutESNcardError(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();

        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ApprovalStatus => 'Approved',
                ApplicationField::HasESNcard => false,
                default => null,
            };
        });

        $result = $application->addApprovalStatus('Paid');
        $this->assertEquals('This status cannot be applied as this application does not have an ESNcard.', $result);
    }

    public function testAddApprovalStatusWithIssue(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();

        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ApprovalStatus => 'Pending',
                ApplicationField::HasESNcard => true,
                default => null,
            };
        });

        // 'Paid' applied to 'Pending' is out of order (must be Approved first)
        $result = $application->addApprovalStatus('Paid');
        $this->assertIsString($result);
        $this->assertStringContainsString('out of order', $result);
    }

    public function testAddApprovalStatusSuccess(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();

        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ApprovalStatus => 'Pending',
                ApplicationField::HasESNcard => true,
                default => null,
            };
        });

        $application->expects($this->once())
            ->method('setValue')
            ->with(ApplicationField::ApprovalStatus, 'Pending/Approved')
            ->willReturnSelf();

        $result = $application->addApprovalStatus('Approved');
        $this->assertTrue($result);
    }

    public function testRemoveApprovalStatus(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue', 'setValue'])
            ->getMock();

        $application->method('getValue')->with(ApplicationField::ApprovalStatus)->willReturn('Approved/Blacklisted');

        $application->expects($this->once())
            ->method('setValue')
            ->with(ApplicationField::ApprovalStatus, 'Approved')
            ->willReturnSelf();

        $application->removeApprovalStatus('Blacklisted');
    }

    public function testReasonsMethods(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $application->method('getValue')
            ->with(ApplicationField::ApprovalStatus)
            ->willReturn('Pending - Document - Expired / Rejected - Photo - Blurry');

        $allReasons = $application->getAllReasons();
        $this->assertCount(2, $allReasons);

        $pendingReasons = $application->getPendingReasons();
        $this->assertCount(1, $pendingReasons);
        $firstPending = reset($pendingReasons);
        $this->assertEquals('Pending', $firstPending->status);

        $rejectionReasons = $application->getRejectionReasons();
        $this->assertCount(1, $rejectionReasons);
        $firstRejection = reset($rejectionReasons);
        $this->assertEquals('Rejected', $firstRejection->status);
    }

    public function testClearPendingStatusesWithReasons(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPendingReasons', 'removeApprovalStatus'])
            ->getMock();

        $pendingReason = new Status('Pending', 'Document', 'Expired');
        $application->method('getPendingReasons')->willReturn([$pendingReason]);

        $removed = [];
        $application->expects($this->exactly(2))
            ->method('removeApprovalStatus')
            ->willReturnCallback(function (string $status) use (&$removed) {
                $removed[] = $status;
            });

        $application->clearPendingStatuses();
        $this->assertContains($pendingReason->toString(), $removed);
        $this->assertContains('Pending', $removed);
    }

    public function testClearPendingStatusesWithoutReasons(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPendingReasons', 'removeApprovalStatus'])
            ->getMock();

        $application->method('getPendingReasons')->willReturn([]);

        $application->expects($this->once())
            ->method('removeApprovalStatus')
            ->with('Pending');

        $application->clearPendingStatuses();
    }

    public function testStatusPredicates(): void
    {
        $statuses = [
            'Approved' => ['isApproved' => true, 'isPaid' => false, 'isRejected' => false, 'isPending' => false, 'isBlacklisted' => false],
            'Paid' => ['isApproved' => true, 'isPaid' => true, 'isRejected' => false, 'isPending' => false, 'isBlacklisted' => false],
            'Issued' => ['isApproved' => true, 'isPaid' => true, 'isRejected' => false, 'isPending' => false, 'isBlacklisted' => false],
            'Delivered' => ['isApproved' => true, 'isPaid' => true, 'isRejected' => false, 'isPending' => false, 'isBlacklisted' => false],
            'Rejected' => ['isApproved' => false, 'isPaid' => false, 'isRejected' => true, 'isPending' => false, 'isBlacklisted' => false],
            'Pending' => ['isApproved' => false, 'isPaid' => false, 'isRejected' => false, 'isPending' => true, 'isBlacklisted' => false],
            'Blacklisted' => ['isApproved' => false, 'isPaid' => false, 'isRejected' => false, 'isPending' => false, 'isBlacklisted' => true],
        ];

        foreach ($statuses as $statusString => $expected) {
            $application = $this->getMockBuilder(Application::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getApprovalStatus'])
                ->getMock();
            $application->method('getApprovalStatus')->willReturn($statusString);

            $this->assertEquals($expected['isApproved'], $application->isApproved(), "Failed isApproved for $statusString");
            $this->assertEquals($expected['isPaid'], $application->isPaid(), "Failed isPaid for $statusString");
            $this->assertEquals($expected['isRejected'], $application->isRejected(), "Failed isRejected for $statusString");
            $this->assertEquals($expected['isPending'], $application->isPending(), "Failed isPending for $statusString");
            $this->assertEquals($expected['isBlacklisted'], $application->isBlacklisted(), "Failed isBlacklisted for $statusString");
        }
    }

    public function testDateGetters(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::DateOfBirth => '2000-05-15',
                ApplicationField::DateCreated => '2026-01-01T12:00:00',
                ApplicationField::DateApproved => '2026-01-02T12:00:00',
                ApplicationField::DatePaid => '2026-01-03T12:00:00',
                ApplicationField::DateLastScanned => '2026-01-04T12:00:00',
                ApplicationField::DateLastModified => '2026-01-05T12:00:00',
                default => null,
            };
        });

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDateOfBirth());
        $this->assertEquals('2000-05-15', $application->getDateOfBirth()->format('Y-m-d'));

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDateCreated());
        $this->assertEquals('2026-01-01T12:00:00', $application->getDateCreated()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDateApproved());
        $this->assertEquals('2026-01-02T12:00:00', $application->getDateApproved()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDatePaid());
        $this->assertEquals('2026-01-03T12:00:00', $application->getDatePaid()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDateLastScanned());
        $this->assertEquals('2026-01-04T12:00:00', $application->getDateLastScanned()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $application->getDateLastModified());
        $this->assertEquals('2026-01-05T12:00:00', $application->getDateLastModified()->format('Y-m-d\TH:i:s'));
    }

    public function testDateGettersWithNull(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $application->method('getValue')->willReturn(null);

        $this->assertNull($application->getDateOfBirth());
        $this->assertNull($application->getDateCreated());
        $this->assertNull($application->getDateApproved());
        $this->assertNull($application->getDatePaid());
        $this->assertNull($application->getDateLastScanned());
        $this->assertNull($application->getDateLastModified());
    }

    /**
     * @throws EntityStorageException
     */
    public function testSave(): void
    {
        $application = $this->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setValue'])
            ->getMock();

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($application, 'membership_application');

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())->method('save')->with($application);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($storage);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $application->expects($this->once())
            ->method('setValue')
            ->with(
                $this->equalTo(ApplicationField::DateLastModified),
                $this->callback(fn($val) => is_string($val) && strlen($val) > 0)
            )
            ->willReturnSelf();

        $application->save();
    }
}
