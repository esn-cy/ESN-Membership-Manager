<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\GuestPass;

use DateInterval;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPass;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use stdClass;

/**
 * Unit tests for GuestPass entity.
 *
 * @covers       \Drupal\esn_membership_manager\Entity\GuestPass\GuestPass
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class GuestPassTest extends MembershipManagerTestCase
{
    /**
     * @throws ReflectionException
     */
    public function testGetFieldEnumClass(): void
    {
        $method = new ReflectionMethod(GuestPass::class, 'getFieldEnumClass');
        $this->assertEquals(GuestPassField::class, $method->invoke(null));
    }

    /**
     * @throws Exception
     */
    public function testPostDeleteWithGoogleWalletEnabled(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'switch_google_wallet' => true,
            ],
        ]);
        $this->container->set('config.factory', $configFactory);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Deleted application @id', ['@id' => '42']);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('deleteApplicationObject')
            ->with('42', 'guest');
        $this->container->set('esn_membership_manager.google_service', $googleService);

        $storage = $this->createMock(EntityStorageInterface::class);
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('getListCacheTags')->willReturn([]);
        $storage->method('getEntityType')->willReturn($entityType);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinition')->with('membership_guest')->willReturn($entityType);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id'])
            ->getMock();
        $guestPass->method('id')->willReturn('42');

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($guestPass, 'membership_guest');

        GuestPass::postDelete($storage, [$guestPass]);
    }

    /**
     * @throws Exception
     */
    public function testPostDeleteWithGoogleWalletDisabled(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'switch' => ['google_wallet' => false],
            ],
        ]);
        $this->container->set('config.factory', $configFactory);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Deleted application @id', ['@id' => '100']);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->never())->method('deleteApplicationObject');
        $this->container->set('esn_membership_manager.google_service', $googleService);

        $storage = $this->createMock(EntityStorageInterface::class);
        $entityType = $this->createMock(EntityTypeInterface::class);
        $entityType->method('getListCacheTags')->willReturn([]);
        $storage->method('getEntityType')->willReturn($entityType);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinition')->with('membership_guest')->willReturn($entityType);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['id'])
            ->getMock();
        $guestPass->method('id')->willReturn('100');

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($guestPass, 'membership_guest');

        GuestPass::postDelete($storage, [$guestPass]);
    }

    public function testGetRefererEmpty(): void
    {
        $item = $this->createMock(FieldItemListInterface::class);
        $item->method('isEmpty')->willReturn(true);

        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $guestPass->method('get')->with(GuestPassField::RefererID->value)->willReturn($item);

        $this->assertNull($guestPass->getReferer());
    }

    public function testGetRefererNotApplicationInstance(): void
    {
        $item = new class {
            public bool $isEmpty = false;
            public mixed $entity = null;

            public function isEmpty(): bool
            {
                return false;
            }
        };
        $item->entity = new stdClass();

        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $guestPass->method('get')->with(GuestPassField::RefererID->value)->willReturn($item);

        $this->assertNull($guestPass->getReferer());
    }

    public function testGetRefererValid(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $item = new class($app) {
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

        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $guestPass->method('get')->with(GuestPassField::RefererID->value)->willReturn($item);

        $this->assertSame($app, $guestPass->getReferer());
    }

    public function testGetFullName(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::Name => 'John',
                GuestPassField::Surname => 'Doe',
                default => null,
            };
        });

        $this->assertEquals('John Doe', $guestPass->getFullName());
    }

    /**
     * @throws Exception
     */
    public function testGetApprovalStatusRedeemed(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::DateRedeemed => '2026-10-08T10:00:00',
                default => null,
            };
        });

        $this->assertEquals('Redeemed', $guestPass->getApprovalStatus());
    }

    /**
     * @throws Exception
     */
    public function testGetApprovalStatusApproved(): void
    {
        $now = new DrupalDateTime();
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturnCallback(function ($field) use ($now) {
            return match ($field) {
                GuestPassField::DateRedeemed => null,
                GuestPassField::DateApproved => $now->format('Y-m-d\TH:i:s'),
                default => null,
            };
        });

        $this->assertEquals('Approved', $guestPass->getApprovalStatus());
    }

    /**
     * @throws Exception
     */
    public function testGetApprovalStatusExpired(): void
    {
        $oldDate = (new DrupalDateTime())->sub(new DateInterval('P10D'));
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturnCallback(function ($field) use ($oldDate) {
            return match ($field) {
                GuestPassField::DateRedeemed => null,
                GuestPassField::DateApproved => $oldDate->format('Y-m-d\TH:i:s'),
                default => null,
            };
        });

        $this->assertEquals('Expired', $guestPass->getApprovalStatus());
    }

    /**
     * @throws Exception
     */
    public function testGetApprovalStatusPending(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturn(null);

        $this->assertEquals('Pending', $guestPass->getApprovalStatus());
    }

    public function testDateGetters(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::DateCreated => '2026-01-01T12:00:00',
                GuestPassField::DateApproved => '2026-01-02T12:00:00',
                GuestPassField::DateRedeemed => '2026-01-03T12:00:00',
                GuestPassField::DateLastModified => '2026-01-04T12:00:00',
                default => null,
            };
        });

        $this->assertInstanceOf(DrupalDateTime::class, $guestPass->getDateCreated());
        $this->assertEquals('2026-01-01T12:00:00', $guestPass->getDateCreated()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $guestPass->getDateApproved());
        $this->assertEquals('2026-01-02T12:00:00', $guestPass->getDateApproved()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $guestPass->getDateRedeemed());
        $this->assertEquals('2026-01-03T12:00:00', $guestPass->getDateRedeemed()->format('Y-m-d\TH:i:s'));

        $this->assertInstanceOf(DrupalDateTime::class, $guestPass->getDateLastModified());
        $this->assertEquals('2026-01-04T12:00:00', $guestPass->getDateLastModified()->format('Y-m-d\TH:i:s'));
    }

    public function testDateGettersWithNull(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getValue'])
            ->getMock();

        $guestPass->method('getValue')->willReturn(null);

        $this->assertNull($guestPass->getDateCreated());
        $this->assertNull($guestPass->getDateApproved());
        $this->assertNull($guestPass->getDateRedeemed());
        $this->assertNull($guestPass->getDateLastModified());
    }

    /**
     * @throws EntityStorageException
     */
    public function testSave(): void
    {
        $guestPass = $this->getMockBuilder(GuestPass::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setValue'])
            ->getMock();

        $entityTypeProp = new ReflectionProperty(EntityBase::class, 'entityTypeId');
        $entityTypeProp->setValue($guestPass, 'membership_guest');

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->expects($this->once())->method('save')->with($guestPass);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($storage);
        $this->container->set('entity_type.manager', $entityTypeManager);

        $guestPass->expects($this->once())
            ->method('setValue')
            ->with(
                $this->equalTo(GuestPassField::DateLastModified),
                $this->callback(fn($val) => is_string($val) && strlen($val) > 0)
            )
            ->willReturnSelf();

        $guestPass->save();
    }
}
