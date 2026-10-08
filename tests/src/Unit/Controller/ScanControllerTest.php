<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Controller\ScanController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\ScanController
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ScanControllerTest extends MembershipManagerTestCase
{
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private GuestPassStorage $guestPassStorage;
    private FileService $fileService;
    private GoogleService $googleService;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->guestPassStorage = $this->createMock(GuestPassStorage::class);

        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->willReturnCallback(function ($id) {
                return match ($id) {
                    'membership_application' => $this->applicationStorage,
                    'membership_guest' => $this->guestPassStorage,
                    default => null,
                };
            });

        $this->fileService = $this->createMock(FileService::class);
        $this->googleService = $this->createMock(GoogleService::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock($this->logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) use ($configFactory, $loggerFactory) {
                return match ($id) {
                    'entity_type.manager' => $this->entityTypeManager,
                    'config.factory' => $configFactory,
                    'esn_membership_manager.file_service' => $this->fileService,
                    'esn_membership_manager.google_service' => $this->googleService,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = ScanController::create($container);
        $this->assertInstanceOf(ScanController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardEmptyCardReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->scanCard($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('No card number was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardInvalidCardReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['card' => 'invalid_number']));
        $response = $controller->scanCard($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid card number was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardApplicationNotFoundReturns404(): void
    {
        $cardNumber = '1234567ABCD';
        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['card' => $cardNumber]));
        $response = $controller->scanCard($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Card/Pass not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardBlacklistedReturns200WithBlacklistedInfo(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);
        $app->expects($this->once())
            ->method('isBlacklisted')
            ->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['card' => $cardNumber]));
        $response = $controller->scanCard($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('BLACKLISTED', $data['name']);
        $this->assertEquals('BLACKLISTED', $data['surname']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardSuccess(): void
    {
        $cardNumber = '1234567ABCD';
        $facePhoto = $this->createMock(FileInterface::class);
        $facePhoto->method('id')->willReturn('55');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('isBlacklisted')->willReturn(false);
        $app->method('getFacePhoto')->willReturn($facePhoto);
        $app->method('getDateLastScanned')->willReturn(new DrupalDateTime('2026-05-01'));
        $app->method('getDatePaid')->willReturn(new DrupalDateTime('2026-04-01'));
        $app->method('getDateApproved')->willReturn(new DrupalDateTime('2026-04-02'));
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'John',
                ApplicationField::Surname => 'Doe',
                ApplicationField::Nationality => 'Cypriot',
                ApplicationField::MobilityStatus => 'Erasmus Student',
                default => null,
            };
        });

        $app->expects($this->once())->method('updateLastScanned');
        $app->expects($this->once())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->fileService->expects($this->once())
            ->method('getFileURL')
            ->with('55')
            ->willReturn('https://example.com/files/photo.jpg');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['card' => $cardNumber]));
        $response = $controller->scanCard($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('John', $data['name']);
        $this->assertEquals('Doe', $data['surname']);
        $this->assertEquals('Cypriot', $data['nationality']);
        $this->assertEquals('Erasmus Student', $data['mobilityStatus']);
        $this->assertEquals('2026-04-01', $data['datePaid']);
        $this->assertEquals('2026-04-02', $data['dateApproved']);
        $this->assertEquals('2026-05-01', $data['lastScanDate']);
        $this->assertEquals('https://example.com/files/photo.jpg', $data['profileImageURL']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanCardSaveExceptionReturns500(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('isBlacklisted')->willReturn(false);
        $app->method('getFacePhoto')->willReturn(null);
        $app->method('getDateLastScanned')->willReturn(null);
        $app->method('save')->willThrowException(new Exception('Disk full'));

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Scan update failed: @message', ['@message' => 'Disk full']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['card' => $cardNumber]));
        $response = $controller->scanCard($request);

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Unable to update last scan date.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanGuestNotFoundThrows404(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $controller->scanCard(new Request([], [], [], [], [], [], json_encode(['card' => $guestToken])));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanGuestSuccessNotPreviouslyRedeemed(): void
    {
        $guestToken = 'GUEST' . str_repeat('B', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $referer->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'HostName',
                ApplicationField::Surname => 'HostSurname',
                ApplicationField::MobilityStatus => 'Erasmus Host',
                default => null,
            };
        });

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('77');
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateRedeemed')->willReturn(null);
        $guestPass->method('getDateApproved')->willReturn(new DrupalDateTime('2026-05-10'));
        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::Name => 'GuestFirst',
                GuestPassField::Surname => 'GuestLast',
                default => null,
            };
        });

        $guestPass->expects($this->once())
            ->method('setValue')
            ->with(GuestPassField::DateRedeemed, $this->isType('string'));
        $guestPass->expects($this->once())
            ->method('save');

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->googleService->expects($this->once())
            ->method('deleteApplicationObject')
            ->with('77', 'guest');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => true,
            ],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->scanCard(new Request([], [], [], [], [], [], json_encode(['card' => $guestToken])));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('GuestFirst', $data['name']);
        $this->assertEquals('GuestLast', $data['surname']);
        $this->assertEquals('HostName', $data['refererName']);
        $this->assertEquals('HostSurname', $data['refererSurname']);
        $this->assertEquals('Erasmus Host', $data['refererMobilityStatus']);
        $this->assertEquals('2026-05-10', $data['dateApproved']);
        $this->assertNull($data['dateRedeemed']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanFreePassSuccess(): void
    {
        $passToken = str_repeat('A', 32);
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('isBlacklisted')->willReturn(false);
        $app->method('getFacePhoto')->willReturn(null);
        $app->method('getDateLastScanned')->willReturn(null);
        $app->method('getDatePaid')->willReturn(null);
        $app->method('getDateApproved')->willReturn(new DrupalDateTime('2026-04-15'));
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'FreePassUser',
                ApplicationField::Surname => 'PassSurname',
                ApplicationField::Nationality => 'Greek',
                ApplicationField::MobilityStatus => 'Student',
                default => null,
            };
        });

        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn($app);

        $app->expects($this->once())->method('updateLastScanned');
        $app->expects($this->once())->method('save');

        $configFactory = $this->getConfigFactoryStub([MembershipSettings::CONFIG_NAME => []]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->scanCard(new Request([], [], [], [], [], [], json_encode(['card' => $passToken])));
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('FreePassUser', $data['name']);
        $this->assertEquals('PassSurname', $data['surname']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanGuestSaveExceptionReturns500(): void
    {
        $guestToken = 'GUEST' . str_repeat('B', 27);
        $referer = $this->createMock(ApplicationInterface::class);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateRedeemed')->willReturn(null);
        $guestPass->expects($this->once())->method('save')
            ->willThrowException(new Exception('Save failed'));

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Scan update failed: @message', ['@message' => 'Save failed']);

        $configFactory = $this->getConfigFactoryStub([MembershipSettings::CONFIG_NAME => []]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->scanCard(new Request([], [], [], [], [], [], json_encode(['card' => $guestToken])));
        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Unable to update redeemed date.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testScanGuestGoogleDeleteExceptionIgnored(): void
    {
        $guestToken = 'GUEST' . str_repeat('C', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $referer->method('getValue')->willReturn('Host');

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('88');
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateRedeemed')->willReturn(null);
        $guestPass->method('getDateApproved')->willReturn(null);
        $guestPass->method('getValue')->willReturn('Guest');

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->googleService->expects($this->once())
            ->method('deleteApplicationObject')
            ->with('88', 'guest')
            ->willThrowException(new Exception('Google error'));

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => true,
            ],
        ]);

        $controller = new ScanController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->googleService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->scanCard(new Request([], [], [], [], [], [], json_encode(['card' => $guestToken])));
        $this->assertEquals(200, $response->getStatusCode());
    }
}
