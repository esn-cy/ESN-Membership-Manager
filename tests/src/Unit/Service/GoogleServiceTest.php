<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\file\FileInterface;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\Sheets\AppendValuesResponse;
use Google\Service\Sheets\UpdateValuesResponse;
use Google\Service\Walletobjects\GenericClass;
use Google\Service\Walletobjects\GenericObject;
use GuzzleHttp\Exception\GuzzleException;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Tests for GoogleService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\GoogleService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class GoogleServiceTest extends MembershipManagerTestCase
{
    protected function getTestConfigFactory(array $overrides = []): ConfigFactoryInterface
    {
        $membershipOverrides = $overrides[MembershipSettings::CONFIG_NAME] ?? [];
        $omniaOverrides = $overrides[OmniaSettings::CONFIG_NAME] ?? [];

        return $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => array_merge([
                'google_spreadsheet_id' => 'sheet_id_123',
                'google_sheet_name' => 'Sheet1',
                'pass_name' => 'ESN Cyprus Pass',
                'guest_pass_name' => 'ESN Guest Pass',
            ], $membershipOverrides),
            OmniaSettings::CONFIG_NAME => array_merge([
                'google_issuer_id' => '338800000002222',
                'google_client_email' => null, // client won't initialize by default
                'google_private_key' => null,
            ], $omniaOverrides),
        ]);
    }

    protected function getGoogleClientMock(): GoogleClient&MockObject
    {
        $client = $this->createMock(GoogleClient::class);
        $client->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
        $client->method('getUniverseDomain')->willReturn('googleapis.com');
        return $client;
    }

    public function testAppendRowReturnsFalseWhenClientNotInitialized(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new GoogleService($configFactory, $fileService, $loggerFactory);
        $result = $service->appendRow(['name' => self::TEST_FULL_NAME]);

        $this->assertFalse($result);
    }

    public function testAppendRowReturnsTrueOnSuccess(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $client = $this->getGoogleClientMock();

        $updates = new UpdateValuesResponse(['updatedCells' => 8]);
        $response = new AppendValuesResponse(['updates' => $updates]);

        $client->expects($this->once())
            ->method('execute')
            ->willReturn($response);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClient'])
            ->getMock();

        $service->method('getClient')->willReturn($client);

        $result = $service->appendRow([
            'date' => '08/10/2026',
            'name' => self::TEST_FULL_NAME,
            'card_number' => self::TEST_CARD_NUMBER,
            'pos' => 'ESN Membership Manager',
            'host' => self::TEST_HOST,
            'nationality' => self::TEST_NATIONALITY,
            'mop' => 'Stripe',
            'amount' => '15.00',
        ]);

        $this->assertTrue($result);
    }

    public function testAppendRowReturnsFalseWhenUpdatedCellsIsZero(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $client = $this->getGoogleClientMock();

        $updates = new UpdateValuesResponse(['updatedCells' => 0]);
        $response = new AppendValuesResponse(['updates' => $updates]);

        $client->expects($this->once())
            ->method('execute')
            ->willReturn($response);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClient'])
            ->getMock();

        $service->method('getClient')->willReturn($client);

        $result = $service->appendRow(['name' => self::TEST_FULL_NAME]);
        $this->assertFalse($result);
    }

    public function testAppendRowHandlesExceptionAndReturnsFalse(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Google Sheets Append Error: @message', ['@message' => 'Network failure']);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $client = $this->getGoogleClientMock();
        $client->expects($this->once())
            ->method('execute')
            ->willThrowException(new Exception('Network failure'));

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClient'])
            ->getMock();

        $service->method('getClient')->willReturn($client);

        $result = $service->appendRow(['name' => self::TEST_FULL_NAME]);
        $this->assertFalse($result);
    }


    /**
     * @throws Exception
     */
    public function testDeleteApplicationObjectCard(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['deleteObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('deleteObject')
            ->with('338800000002222.esncard-101')
            ->willReturn(true);

        $result = $service->deleteApplicationObject('101', 'card');
        $this->assertTrue($result);
    }

    /**
     * @throws Exception
     */
    public function testDeleteApplicationObjectPass(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['deleteObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('deleteObject')
            ->with('338800000002222.pass-202')
            ->willReturn(true);

        $result = $service->deleteApplicationObject('202', 'pass');
        $this->assertTrue($result);
    }

    /**
     * @throws Exception
     */
    public function testDeleteApplicationObjectGuest(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['deleteObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('deleteObject')
            ->with('338800000002222.guest-303')
            ->willReturn(true);

        $result = $service->deleteApplicationObject('303', 'guest');
        $this->assertTrue($result);
    }

    public function testDeleteApplicationObjectThrowsOnUnsupportedType(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new GoogleService($configFactory, $fileService, $loggerFactory);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported application type.');

        $service->deleteApplicationObject('101', 'unknown');
    }

    /**
     * @throws Exception
     */
    public function testGetESNcardClassReturnsExistingClass(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_card')
            ->willReturn('338800000002222.esn_membership_manager_card');

        $service->expects($this->never())->method('createClass');

        $classId = $service->getESNcardClass();
        $this->assertEquals('338800000002222.esn_membership_manager_card', $classId);
    }

    /**
     * @throws Exception
     */
    public function testGetESNcardClassCreatesClassWhenNotFound(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_card')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('createClass')
            ->with($this->callback(function (GenericClass $class) {
                $this->assertEquals('338800000002222.esn_membership_manager_card', $class->id);
                $this->assertEquals('FOIL_SHIMMER', $class->securityAnimation['animationType']);
                $this->assertEquals('ONE_USER_ALL_DEVICES', $class->multipleDevicesAndHoldersAllowedStatus);
                return true;
            }))
            ->willReturn('338800000002222.esn_membership_manager_card');

        $classId = $service->getESNcardClass();
        $this->assertEquals('338800000002222.esn_membership_manager_card', $classId);
    }

    /**
     * @throws Exception
     */
    public function testGetESNcardClassReturnsCachedProperty(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new GoogleService($configFactory, $fileService, $loggerFactory);

        $refProperty = new ReflectionProperty(GoogleService::class, 'cardClassID');
        $refProperty->setValue($service, 'cached.card.class.id');

        $this->assertEquals('cached.card.class.id', $service->getESNcardClass());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetPassClassAndGuestPassClassReturnCachedProperties(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new GoogleService($configFactory, $fileService, $loggerFactory);

        $refPassProperty = new ReflectionProperty(GoogleService::class, 'passClassID');
        $refPassProperty->setValue($service, 'cached.pass.class.id');

        $refPassMethod = new ReflectionMethod(GoogleService::class, 'getPassClass');
        $this->assertEquals('cached.pass.class.id', $refPassMethod->invoke($service));

        $refGuestProperty = new ReflectionProperty(GoogleService::class, 'guestClassID');
        $refGuestProperty->setValue($service, 'cached.guest.class.id');

        $refGuestMethod = new ReflectionMethod(GoogleService::class, 'getGuestPassClass');
        $this->assertEquals('cached.guest.class.id', $refGuestMethod->invoke($service));
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testGetESNcardObjectReturnsExistingLink(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('555');

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('esncard-555')
            ->willReturn('https://pay.google.com/gp/v/save/existing_token');

        $result = $service->getESNcardObject($application);
        $this->assertEquals('https://pay.google.com/gp/v/save/existing_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testCreateESNcardObjectWhenObjectNotFound(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('501');

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('100');
        $application->method('getFullName')->willReturn(self::TEST_FULL_NAME);
        $application->method('getDatePaid')->willReturn(new DrupalDateTime('2026-09-01 14:30:00'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2001-04-10'));
        $application->method('getFacePhoto')->willReturn($facePhoto);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Section => self::TEST_SECTION,
                ApplicationField::Nationality => self::TEST_NATIONALITY,
                ApplicationField::HostInstitution => self::TEST_HOST,
                ApplicationField::ESNcardNumber => self::TEST_CARD_NUMBER,
                default => null,
            };
        });

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject', 'createObject', 'uploadPrivateImage', 'getClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('esncard-100')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_card')
            ->willReturn('338800000002222.esn_membership_manager_card');

        $service->expects($this->once())
            ->method('uploadPrivateImage')
            ->with('501')
            ->willReturn('priv_img_501');

        $service->expects($this->once())
            ->method('createObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.esncard-100', $object->id);
                $this->assertEquals('338800000002222.esn_membership_manager_card', $object->classId);
                $this->assertEquals(self::TEST_CARD_NUMBER, $object->barcode->value);
                $this->assertEquals('CODE_128', $object->barcode->type);
                $this->assertEquals(self::TEST_FULL_NAME, $object->header->defaultValue->value);
                $this->assertEquals('ESNcard', $object->cardTitle->defaultValue->value);
                $this->assertEquals('#2e3192', $object->hexBackgroundColor);
                $this->assertEquals('ACTIVE', $object->state);

                $textModules = [];
                foreach ($object->textModulesData as $tm) {
                    $textModules[$tm['id']] = $tm['body'];
                }
                $this->assertEquals('Cypriot', $textModules['nationality']);
                $this->assertEquals('10/04/2001', $textModules['dob']);
                $this->assertEquals('University of Cyprus', $textModules['studies_at']);
                $this->assertEquals('Nicosia', $textModules['esn_section']); // Stripped 'ESN ' prefix
                $this->assertEquals('01/09/2026', $textModules['valid_since']);

                $this->assertCount(1, $object->imageModulesData);
                $this->assertEquals('priv_img_501', $object->imageModulesData[0]['mainImage']->privateImageId);

                return true;
            }))
            ->willReturn('https://pay.google.com/gp/v/save/new_esncard_token');

        $result = $service->getESNcardObject($application);
        $this->assertEquals('https://pay.google.com/gp/v/save/new_esncard_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testGetFreePassObjectReturnsExistingLink(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('666');

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('pass-666')
            ->willReturn('https://pay.google.com/gp/v/save/pass_token');

        $result = $service->getFreePassObject($application);
        $this->assertEquals('https://pay.google.com/gp/v/save/pass_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testCreateFreePassObjectWhenObjectNotFoundAndClassNotExists(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('200');
        $application->method('getFullName')->willReturn('Jane Smith');
        $application->method('getDateApproved')->willReturn(new DrupalDateTime('2026-09-15 10:00:00'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2002-06-20'));
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Greek',
                ApplicationField::MobilityStatus => 'Erasmus+ Studies',
                ApplicationField::PassToken => 'pass_token_xyz',
                default => null,
            };
        });

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject', 'createObject', 'getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('pass-200')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_pass')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('createClass')
            ->with($this->callback(function (GenericClass $class) {
                $this->assertEquals('338800000002222.esn_membership_manager_pass', $class->id);
                $this->assertEquals('FOIL_SHIMMER', $class->securityAnimation['animationType']);
                $this->assertEquals('ONE_USER_ALL_DEVICES', $class->multipleDevicesAndHoldersAllowedStatus);
                return true;
            }))
            ->willReturn('338800000002222.esn_membership_manager_pass');

        $service->expects($this->once())
            ->method('createObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.pass-200', $object->id);
                $this->assertEquals('338800000002222.esn_membership_manager_pass', $object->classId);
                $this->assertEquals('pass_token_xyz', $object->barcode->value);
                $this->assertEquals('PASS_TOKEN_XYZ', $object->barcode->alternateText);
                $this->assertEquals('QR_CODE', $object->barcode->type);
                $this->assertEquals('Jane Smith', $object->header->defaultValue->value);
                $this->assertEquals('ESN Cyprus Pass', $object->cardTitle->defaultValue->value);
                $this->assertEquals('#00aeef', $object->hexBackgroundColor);

                $textModules = [];
                foreach ($object->textModulesData as $tm) {
                    $textModules[$tm['id']] = $tm['body'];
                }
                $this->assertEquals('Greek', $textModules['nationality']);
                $this->assertEquals('20/06/2002', $textModules['dob']);
                $this->assertEquals('Erasmus+ Studies', $textModules['mobility_status']);
                $this->assertEquals('15/09/2026', $textModules['valid_since']);

                return true;
            }))
            ->willReturn('https://pay.google.com/gp/v/save/new_freepass_token');

        $result = $service->getFreePassObject($application);
        $this->assertEquals('https://pay.google.com/gp/v/save/new_freepass_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testCreateFreePassObjectWhenClassAlreadyExists(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('201');
        $application->method('getFullName')->willReturn('Jane Smith');
        $application->method('getDateApproved')->willReturn(new DrupalDateTime('2026-09-15 10:00:00'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2002-06-20'));
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Greek',
                ApplicationField::MobilityStatus => 'Erasmus+ Studies',
                ApplicationField::PassToken => 'pass_token_xyz',
                default => null,
            };
        });

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject', 'createObject', 'getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('pass-201')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_pass')
            ->willReturn('338800000002222.esn_membership_manager_pass');

        $service->expects($this->never())->method('createClass');

        $service->expects($this->once())
            ->method('createObject')
            ->willReturn('https://pay.google.com/gp/v/save/existing_class_token');

        $result = $service->getFreePassObject($application);
        $this->assertEquals('https://pay.google.com/gp/v/save/existing_class_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testGetGuestPassObjectReturnsExistingLink(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('777');

        $referrer = $this->createMock(ApplicationInterface::class);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('guest-777')
            ->willReturn('https://pay.google.com/gp/v/save/guest_token');

        $result = $service->getGuestPassObject($guestPass, $referrer);
        $this->assertEquals('https://pay.google.com/gp/v/save/guest_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testCreateGuestPassObjectWhenObjectNotFoundAndClassNotExists(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('300');
        $guestPass->method('getFullName')->willReturn('Bob Guest');
        $guestPass->method('getDateApproved')->willReturn(new DrupalDateTime('2026-10-01 12:00:00'));
        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::PassToken => 'guest_token_abc',
                default => null,
            };
        });

        $referrer = $this->createMock(ApplicationInterface::class);
        $referrer->method('getFullName')->willReturn('Alice Referrer');
        $referrer->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::MobilityStatus => 'Erasmus+ Traineeship',
                default => null,
            };
        });

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject', 'createObject', 'getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('guest-300')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_guest')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('createClass')
            ->with($this->callback(function (GenericClass $class) {
                $this->assertEquals('338800000002222.esn_membership_manager_guest', $class->id);
                $this->assertEquals('MULTIPLE_HOLDERS', $class->multipleDevicesAndHoldersAllowedStatus);
                return true;
            }))
            ->willReturn('338800000002222.esn_membership_manager_guest');

        $service->expects($this->once())
            ->method('createObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.guest-300', $object->id);
                $this->assertEquals('338800000002222.esn_membership_manager_guest', $object->classId);
                $this->assertEquals('guest_token_abc', $object->barcode->value);
                $this->assertEquals('GUEST_TOKEN_ABC', $object->barcode->alternateText);
                $this->assertEquals('AZTEC', $object->barcode->type);
                $this->assertEquals('Bob Guest', $object->header->defaultValue->value);
                $this->assertEquals('ESN Guest Pass', $object->cardTitle->defaultValue->value);
                $this->assertEquals('#ec008c', $object->hexBackgroundColor);

                $textModules = [];
                foreach ($object->textModulesData as $tm) {
                    $textModules[$tm['id']] = $tm['body'];
                }
                $this->assertEquals('Alice Referrer', $textModules['referer_name']);
                $this->assertEquals('Erasmus+ Traineeship', $textModules['referer_mobility_status']);
                $this->assertEquals('08/10/2026', $textModules['valid_until']);

                return true;
            }))
            ->willReturn('https://pay.google.com/gp/v/save/new_guest_token');

        $result = $service->getGuestPassObject($guestPass, $referrer);
        $this->assertEquals('https://pay.google.com/gp/v/save/new_guest_token', $result);
    }

    /**
     * @throws GuzzleException
     * @throws \Google\Service\Exception
     */
    public function testCreateGuestPassObjectWhenClassAlreadyExists(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('301');
        $guestPass->method('getFullName')->willReturn('Bob Guest');
        $guestPass->method('getDateApproved')->willReturn(new DrupalDateTime('2026-10-01 12:00:00'));
        $guestPass->method('getValue')->willReturnMap([
            [GuestPassField::PassToken, 'guest_token_abc'],
        ]);

        $referrer = $this->createMock(ApplicationInterface::class);
        $referrer->method('getFullName')->willReturn('Alice Referrer');
        $referrer->method('getValue')->willReturnMap([
            [ApplicationField::MobilityStatus, 'Erasmus+ Traineeship'],
        ]);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getObject', 'createObject', 'getClass', 'createClass'])
            ->getMock();

        $service->expects($this->once())
            ->method('getObject')
            ->with('guest-301')
            ->willReturn(null);

        $service->expects($this->once())
            ->method('getClass')
            ->with('esn_membership_manager_guest')
            ->willReturn('338800000002222.esn_membership_manager_guest');

        $service->expects($this->never())->method('createClass');

        $service->expects($this->once())
            ->method('createObject')
            ->willReturn('https://pay.google.com/gp/v/save/existing_guest_class_token');

        $result = $service->getGuestPassObject($guestPass, $referrer);
        $this->assertEquals('https://pay.google.com/gp/v/save/existing_guest_class_token', $result);
    }

    /**
     * @throws GuzzleException
     */
    public function testUpdateApplicationObjectCard(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('502');

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('102');
        $application->method('getFullName')->willReturn('George NonPrefix');
        $application->method('getDatePaid')->willReturn(new DrupalDateTime('2026-09-01'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2000-01-01'));
        $application->method('getFacePhoto')->willReturn($facePhoto);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Section => 'NonPrefixSection',
                ApplicationField::Nationality => 'Greek',
                ApplicationField::HostInstitution => 'TEPAK',
                ApplicationField::ESNcardNumber => '9999999ABCD2',
                default => null,
            };
        });

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['uploadPrivateImage', 'getClass', 'updateObject'])
            ->getMock();

        $service->method('getClass')->willReturn('338800000002222.esn_membership_manager_card');
        $service->method('uploadPrivateImage')->willReturn('priv_img_502');

        $service->expects($this->once())
            ->method('updateObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.esncard-102', $object->id);
                $textModules = [];
                foreach ($object->textModulesData as $tm) {
                    $textModules[$tm['id']] = $tm['body'];
                }
                $this->assertEquals('NonPrefixSection', $textModules['esn_section']);
                return true;
            }))
            ->willReturn(true);

        $result = $service->updateApplicationObject($application, 'card');
        $this->assertTrue($result);
    }

    /**
     * @throws GuzzleException
     */
    public function testUpdateApplicationObjectPass(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('202');
        $application->method('getFullName')->willReturn('Jane Smith');
        $application->method('getDateApproved')->willReturn(new DrupalDateTime('2026-09-15'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2002-06-20'));
        $application->method('getValue')->willReturnMap([
            [ApplicationField::Nationality, 'Greek'],
            [ApplicationField::MobilityStatus, 'Erasmus+ Studies'],
            [ApplicationField::PassToken, 'pass_token_xyz'],
        ]);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClass', 'updateObject'])
            ->getMock();

        $service->method('getClass')->willReturn('338800000002222.esn_membership_manager_pass');
        $service->expects($this->once())
            ->method('updateObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.pass-202', $object->id);
                return true;
            }))
            ->willReturn(true);

        $result = $service->updateApplicationObject($application, 'pass');
        $this->assertTrue($result);
    }

    /**
     * @throws GuzzleException
     */
    public function testUpdateApplicationObjectGuest(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('302');
        $guestPass->method('getFullName')->willReturn('Bob Guest');
        $guestPass->method('getDateApproved')->willReturn(new DrupalDateTime('2026-10-01'));
        $guestPass->method('getValue')->willReturnMap([
            [GuestPassField::PassToken, 'guest_token_abc'],
        ]);

        $referrer = $this->createMock(ApplicationInterface::class);
        $referrer->method('getFullName')->willReturn('Alice Referrer');
        $referrer->method('getValue')->willReturnMap([
            [ApplicationField::MobilityStatus, 'Erasmus+ Traineeship'],
        ]);

        $service = $this->getMockBuilder(GoogleService::class)
            ->setConstructorArgs([$configFactory, $fileService, $loggerFactory])
            ->onlyMethods(['getClass', 'updateObject'])
            ->getMock();

        $service->method('getClass')->willReturn('338800000002222.esn_membership_manager_guest');
        $service->expects($this->once())
            ->method('updateObject')
            ->with($this->callback(function (GenericObject $object) {
                $this->assertEquals('338800000002222.guest-302', $object->id);
                return true;
            }))
            ->willReturn(true);

        $result = $service->updateApplicationObject($guestPass, 'guest', $referrer);
        $this->assertTrue($result);
    }

    /**
     * @throws GuzzleException
     */
    public function testUpdateApplicationObjectThrowsOnUnsupportedType(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $fileService = $this->createMock(FileService::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $service = new GoogleService($configFactory, $fileService, $loggerFactory);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported application type.');

        $service->updateApplicationObject($application, 'unsupported_type');
    }
}
