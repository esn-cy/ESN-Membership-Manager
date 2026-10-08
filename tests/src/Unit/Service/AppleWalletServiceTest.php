<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\file\FileInterface;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\ClientInterface;

/**
 * Tests for AppleWalletService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\AppleWalletService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class AppleWalletServiceTest extends MembershipManagerTestCase
{
    protected Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        $this->settings = new Settings(['hash_salt' => 'test_salt_secret']);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')
            ->willReturn('https://example.com/api/v1/log');
        $this->container->set('url_generator', $urlGenerator);
    }

    protected function getTestConfigFactory(): ConfigFactoryInterface
    {
        return $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_certificate_p12' => 'fake_p12_content',
                'apple_certificate_password' => 'cert_pass',
                'apple_certificate_pem' => 'fake_pem_content',
                'apple_pass_type_id' => 'pass.org.esn.membership',
                'pass_name' => 'ESN Cyprus Pass',
                'guest_pass_name' => 'ESN Guest Pass',
            ],
            OmniaSettings::CONFIG_NAME => [
                'organisation_name' => 'ESN Cyprus',
                'apple_team_id' => 'TEAM12345',
            ],
        ]);
    }

    /**
     * @throws Exception
     */
    public function testCreateESNcardBuildsPassDataCorrectly(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/modules/custom/esn_membership_manager');
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileMimeType')->with('501')->willReturn('image/png');
        $fileService->method('getFilePath')->with('501')->willReturn('/files/photo.png');

        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('501');

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('100');
        $application->method('getFullName')->willReturn(self::TEST_FULL_NAME);
        $application->method('getDatePaid')->willReturn(new DrupalDateTime('2026-09-01'));
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

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['createPass'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPass')
            ->with(
                $this->callback(function (array $passData) {
                    $this->assertEquals('esncard-100', $passData['serialNumber']);
                    $this->assertEquals('ESNcard', $passData['description']);
                    $this->assertEquals('pass.org.esn.membership', $passData['passTypeIdentifier']);
                    $this->assertEquals('TEAM12345', $passData['teamIdentifier']);
                    $this->assertEquals('ESN Cyprus', $passData['organizationName']);
                    $this->assertEquals('https://example.com/api', $passData['webServiceURL']);

                    // Verify fields
                    $generic = $passData['generic'];
                    $this->assertEquals(self::TEST_FULL_NAME, $generic['primaryFields'][0]['value']);
                    $this->assertEquals(self::TEST_NATIONALITY, $generic['secondaryFields'][0]['value']);
                    $this->assertEquals('10/04/2001', $generic['secondaryFields'][1]['value']);
                    $this->assertEquals(self::TEST_HOST, $generic['auxiliaryFields'][0]['value']);
                    // Verify 'ESN ' prefix was stripped from section
                    $this->assertEquals('Nicosia', $generic['auxiliaryFields'][1]['value']);
                    $this->assertEquals('01/09/2026', $generic['auxiliaryFields'][2]['value']);

                    // Barcode
                    $this->assertEquals(self::TEST_CARD_NUMBER, $passData['barcodes'][0]['message']);
                    $this->assertEquals('PKBarcodeFormatCode128', $passData['barcodes'][0]['format']);
                    return true;
                }),
                $this->callback(function (array $images) {
                    $this->assertArrayHasKey('thumbnail.png', $images);
                    $this->assertEquals('/files/photo.png', $images['thumbnail.png']);
                    $this->assertArrayHasKey('logo.png', $images);
                    return true;
                }),
                'fake_p12_content',
                'cert_pass'
            )
            ->willReturn('raw_pkpass_bytes');

        $result = $service->createESNcard($application);
        $this->assertEquals('raw_pkpass_bytes', $result);
    }

    /**
     * @throws Exception
     */
    public function testCreateESNcardConvertsNonPngImageToPng(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/modules/custom/esn_membership_manager');
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $im = imagecreatetruecolor(1, 1);
        ob_start();
        imagejpeg($im);
        $jpegData = ob_get_clean();

        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileMimeType')->with('501')->willReturn('image/jpeg');
        $fileService->method('readFile')->with('501')->willReturn($jpegData);
        $fileService->expects($this->once())
            ->method('replaceFileData')
            ->with('501', $this->isType('string'))
            ->willReturn(true);
        $fileService->method('getFilePath')->with('501')->willReturn('/files/photo_converted.png');

        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('501');

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('101');
        $application->method('getFullName')->willReturn(self::TEST_FULL_NAME);
        $application->method('getDatePaid')->willReturn(new DrupalDateTime('2026-09-01'));
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

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['createPass'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPass')
            ->with(
                $this->anything(),
                $this->callback(function (array $images) {
                    $this->assertArrayHasKey('thumbnail.png', $images);
                    $this->assertEquals('/files/photo_converted.png', $images['thumbnail.png']);
                    return true;
                }),
                'fake_p12_content',
                'cert_pass'
            )
            ->willReturn('raw_pkpass_bytes');

        $result = $service->createESNcard($application);
        $this->assertEquals('raw_pkpass_bytes', $result);
    }

    /**
     * @throws Exception
     */
    public function testCreateESNcardWithEmptyMimeTypeSkipsThumbnail(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/modules/custom/esn_membership_manager');
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $fileService = $this->createMock(FileService::class);
        $fileService->method('getFileMimeType')->with('501')->willReturn(null);

        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('501');

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('102');
        $application->method('getFullName')->willReturn(self::TEST_FULL_NAME);
        $application->method('getDatePaid')->willReturn(new DrupalDateTime('2026-09-01'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2001-04-10'));
        $application->method('getFacePhoto')->willReturn($facePhoto);
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Section => 'Nicosia',
                ApplicationField::Nationality => self::TEST_NATIONALITY,
                ApplicationField::HostInstitution => self::TEST_HOST,
                ApplicationField::ESNcardNumber => self::TEST_CARD_NUMBER,
                default => null,
            };
        });

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['createPass'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPass')
            ->with(
                $this->anything(),
                $this->callback(function (array $images) {
                    $this->assertArrayNotHasKey('thumbnail.png', $images);
                    return true;
                }),
                'fake_p12_content',
                'cert_pass'
            )
            ->willReturn('raw_pkpass_bytes');

        $result = $service->createESNcard($application);
        $this->assertEquals('raw_pkpass_bytes', $result);
    }

    /**
     * @throws Exception
     */
    public function testCreateFreePassBuildsPassDataCorrectly(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/modules/custom/esn_membership_manager');
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $fileService = $this->createMock(FileService::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('200');
        $application->method('getFullName')->willReturn('Jane Smith');
        $application->method('getDateApproved')->willReturn(new DrupalDateTime('2026-09-05'));
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2002-08-20'));
        $application->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Greek',
                ApplicationField::MobilityStatus => 'Erasmus+ Studies',
                ApplicationField::HostInstitution => 'European University Cyprus',
                ApplicationField::PassToken => 'PASS_TOKEN_QR_123',
                default => null,
            };
        });

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['createPass'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPass')
            ->with(
                $this->callback(function (array $passData) {
                    $this->assertEquals('free_pass-200', $passData['serialNumber']);
                    $this->assertEquals('ESN Cyprus Pass', $passData['description']);
                    $this->assertEquals('rgb(0, 174, 239)', $passData['backgroundColor']);

                    // Verify QR barcode
                    $this->assertEquals('PKBarcodeFormatQR', $passData['barcodes'][0]['format']);
                    $this->assertEquals('PASS_TOKEN_QR_123', $passData['barcodes'][0]['message']);

                    $generic = $passData['generic'];
                    $this->assertEquals('Jane Smith', $generic['primaryFields'][0]['value']);
                    $this->assertEquals('Greek', $generic['secondaryFields'][0]['value']);
                    $this->assertEquals('Erasmus+ Studies', $generic['secondaryFields'][1]['value']);
                    $this->assertEquals('20/08/2002', $generic['secondaryFields'][2]['value']);
                    $this->assertEquals('05/09/2026', $generic['auxiliaryFields'][1]['value']);
                    return true;
                }),
                $this->isType('array'),
                'fake_p12_content',
                'cert_pass'
            )
            ->willReturn('raw_freepass_bytes');

        $result = $service->createFreePass($application);
        $this->assertEquals('raw_freepass_bytes', $result);
    }

    /**
     * @throws Exception
     */
    public function testCreateGuestPassBuildsPassDataCorrectly(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/modules/custom/esn_membership_manager');
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $fileService = $this->createMock(FileService::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('300');
        $guestPass->method('getFullName')->willReturn('Guest Visitor');
        $guestPass->method('getDateApproved')->willReturn(new DrupalDateTime('2026-10-01'));
        $guestPass->method('getValue')->with(GuestPassField::PassToken)->willReturn('GUEST_TOKEN_AZTEC_999');

        $referer = $this->createMock(ApplicationInterface::class);
        $referer->method('getFullName')->willReturn('Student Host');
        $referer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['createPass'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPass')
            ->with(
                $this->callback(function (array $passData) {
                    $this->assertEquals('guest-300', $passData['serialNumber']);
                    $this->assertEquals('ESN Guest Pass', $passData['description']);
                    $this->assertEquals('rgb(236, 0, 140)', $passData['backgroundColor']);

                    // Guest pass has Aztec barcode
                    $this->assertEquals('PKBarcodeFormatAztec', $passData['barcodes'][0]['format']);
                    $this->assertEquals('GUEST_TOKEN_AZTEC_999', $passData['barcodes'][0]['message']);

                    // Verify webServiceURL and authenticationToken were unset
                    $this->assertArrayNotHasKey('webServiceURL', $passData);
                    $this->assertArrayNotHasKey('authenticationToken', $passData);

                    $generic = $passData['generic'];
                    $this->assertEquals('Guest Visitor', $generic['primaryFields'][0]['value']);
                    $this->assertEquals('Student Host', $generic['secondaryFields'][0]['value']);
                    $this->assertEquals('Erasmus+ Studies', $generic['auxiliaryFields'][0]['value']);
                    // Expiry is +7 days (08/10/2026)
                    $this->assertEquals('08/10/2026', $generic['auxiliaryFields'][1]['value']);
                    return true;
                }),
                $this->isType('array'),
                'fake_p12_content',
                'cert_pass'
            )
            ->willReturn('raw_guestpass_bytes');

        $result = $service->createGuestPass($guestPass, $referer);
        $this->assertEquals('raw_guestpass_bytes', $result);
    }

    public function testSendApplicationUpdateNotificationDelegatesToBaseMethod(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $module = $this->createMock(Extension::class);
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $fileService = $this->createMock(FileService::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(AppleWalletService::class)
            ->setConstructorArgs([$configFactory, $moduleHandler, $fileService, $httpClient, $this->settings, $loggerFactory])
            ->onlyMethods(['sendUpdateNotification'])
            ->getMock();

        $service->expects($this->once())
            ->method('sendUpdateNotification')
            ->with(
                'device_push_token_abc',
                'pass.org.esn.membership',
                'fake_pem_content',
                'cert_pass'
            )
            ->willReturn(true);

        $result = $service->sendApplicationUpdateNotification('device_push_token_abc');
        $this->assertTrue($result);
    }
}
