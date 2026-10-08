<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Controller\AppleWalletController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\AppleWalletController
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class AppleWalletControllerTest extends MembershipManagerTestCase
{
    private AppleWalletService $appleWalletService;
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private GuestPassStorage $guestPassStorage;
    private Connection $database;
    private Settings $settings;
    private FloodInterface $flood;
    private LoggerChannelInterface $logger;
    private string $hashSalt = 'test_hash_salt_12345';

    protected function setUp(): void
    {
        parent::setUp();

        $this->appleWalletService = $this->createMock(AppleWalletService::class);
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

        $this->database = $this->createMock(Connection::class);
        $this->settings = new Settings(['hash_salt' => $this->hashSalt]);
        $this->flood = $this->createMock(FloodInterface::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
    }

    private function getAuthHeader(string $serialNumber): string
    {
        $token = hash('sha256', $serialNumber . $this->hashSalt);
        return 'ApplePass ' . $token;
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
                    'esn_membership_manager.apple_wallet_service' => $this->appleWalletService,
                    'entity_type.manager' => $this->entityTypeManager,
                    'database' => $this->database,
                    'config.factory' => $configFactory,
                    'settings' => $this->settings,
                    'flood' => $this->flood,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = AppleWalletController::create($container);
        $this->assertInstanceOf(AppleWalletController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadEmptyIdentifierThrows400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No identifier was provided.');
        $controller->download('');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadInvalidIdentifierThrows400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('An invalid identifier was provided.');
        $controller->download('invalid_format');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadESNcardNotFoundThrows404(): void
    {
        $cardNumber = '1234567ABCD';
        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No application was provided.');
        $controller->download($cardNumber);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadESNcardSuccess(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->appleWalletService->expects($this->once())
            ->method('createESNcard')
            ->with($app)
            ->willReturn('PKPASS_BINARY_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->download($cardNumber);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('PKPASS_BINARY_BYTES', $response->getContent());
        $this->assertEquals('application/vnd.apple.pkpass', $response->headers->get('Content-Type'));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadGuestPassSuccess(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->appleWalletService->expects($this->once())
            ->method('createGuestPass')
            ->with($guestPass, $referer)
            ->willReturn('GUEST_PKPASS_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->download($guestToken);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('GUEST_PKPASS_BYTES', $response->getContent());
        $this->assertEquals('application/vnd.apple.pkpass', $response->headers->get('Content-Type'));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationInvalidAuthReturns401(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', 'InvalidToken');

        $response = $controller->handleDeviceRegistration($request, 'dev1', 'passType1', 'serial1');
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationPostMissingPushTokenReturns400(): void
    {
        $serial = 'esncard-100';
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = Request::create('/register', 'POST', [], [], [], [], json_encode([]));
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, 'dev1', 'passType1', $serial);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationPostAlreadyExistsReturns200(): void
    {
        $serial = 'esncard-100';
        $select = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(1);
        $countQuery->method('execute')->willReturn($stmt);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);
        $this->database->method('select')->willReturn($select);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = Request::create('/register', 'POST', [], [], [], [], json_encode(['pushToken' => 'ptoken_123']));
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, 'dev1', 'passType1', $serial);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationPostSuccessReturns201(): void
    {
        $serial = 'esncard-100';
        $select = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(0);
        $countQuery->method('execute')->willReturn($stmt);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);
        $this->database->method('select')->willReturn($select);

        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->expects($this->once())->method('execute');
        $this->database->method('insert')->willReturn($insert);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = Request::create('/register', 'POST', [], [], [], [], json_encode(['pushToken' => 'ptoken_123']));
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, 'dev1', 'passType1', $serial);
        $this->assertEquals(201, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationDeleteSuccessReturns200(): void
    {
        $serial = 'esncard-100';
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->expects($this->once())->method('execute');
        $this->database->method('delete')->willReturn($delete);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = Request::create('/register', 'DELETE');
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, 'dev1', 'passType1', $serial);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetUpdatablePassesNoPassesReturns204(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchCol')->willReturn([]);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->getUpdatablePasses(new Request(), 'dev1', 'passType1');
        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetUpdatablePassesWithUpdatedPassesReturnsJsonResponse(): void
    {
        $serial = 'esncard-100';
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchCol')->willReturn([$serial]);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-15 12:00:00'));
        $this->applicationStorage->method('load')->with('100')->willReturn($app);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['passesUpdatedSince' => '1000']);
        $response = $controller->getUpdatablePasses($request, 'dev1', 'passType1');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals([$serial], $data['serialNumbers']);
        $this->assertNotEmpty($data['lastUpdated']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassTypeMismatchReturns204(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => 'pass.org.esn.membership',
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->getLatestPass(new Request(), 'wrong_pass_type', 'serial1');
        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassSuccess(): void
    {
        $serial = 'esncard-100';
        $passType = 'pass.org.esn.membership';

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::ESNcardNumber)->willReturn('1234567ABCD');
        $app->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));
        $this->applicationStorage->method('load')->with('100')->willReturn($app);
        $this->applicationStorage->method('getByESNcard')->with('1234567ABCD')->willReturn($app);

        $this->appleWalletService->expects($this->once())
            ->method('createESNcard')
            ->with($app)
            ->willReturn('PKPASS_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('PKPASS_BYTES', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testLogMessage(): void
    {
        $this->flood->expects($this->once())
            ->method('isAllowed')
            ->with('esn_membership_manager.apple_log', 5, 60)
            ->willReturn(true);

        $this->flood->expects($this->once())
            ->method('register')
            ->with('esn_membership_manager.apple_log', 60);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Apple Wallet Device Log: @message', ['@message' => 'device log message']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['logs' => ['device log message']]));
        $response = $controller->logMessage($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadFreePassSuccessWithDateCreatedFallback(): void
    {
        $passToken = str_repeat('B', 32);
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getDateLastModified')->willReturn(null);
        $app->method('getDateCreated')->willReturn(new DrupalDateTime('2026-04-01'));

        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn($app);

        $this->appleWalletService->expects($this->once())
            ->method('createFreePass')
            ->with($app)
            ->willReturn('FREE_PASS_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->download($passToken);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('FREE_PASS_BYTES', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadServiceExceptionThrows500(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->appleWalletService->expects($this->once())
            ->method('createESNcard')
            ->willThrowException(new Exception('Apple cert missing'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Creation of Apple Wallet Pass failed: @message', ['@message' => 'Apple cert missing']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unable to generate your Apple Wallet Pass.');
        $controller->download($cardNumber);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadGuestPassNotFoundThrows404(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Guest Pass not found.');
        $controller->download($guestToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadGuestPassMissingRefererThrows404(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn(null);

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Guest Pass not found.');
        $controller->download($guestToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadGuestPassDateCreatedFallback(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateLastModified')->willReturn(null);
        $guestPass->method('getDateCreated')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->appleWalletService->expects($this->once())
            ->method('createGuestPass')
            ->with($guestPass, $referer)
            ->willReturn('GUEST_PKPASS_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->download($guestToken);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testDownloadGuestPassServiceExceptionThrows500(): void
    {
        $guestToken = 'GUEST' . str_repeat('A', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);
        $guestPass->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->appleWalletService->expects($this->once())
            ->method('createGuestPass')
            ->willThrowException(new Exception('Guest pass failure'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Creation of Apple Wallet Pass failed: @message', ['@message' => 'Guest pass failure']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unable to generate your Apple Wallet Pass.');
        $controller->download($guestToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationInsertExceptionReturns500(): void
    {
        $serial = 'esncard-123';
        $deviceLib = 'device_abc';
        $passType = 'pass.com.example';

        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchField')->willReturn(0);

        $select = $this->createMock(SelectInterface::class);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturnSelf();
        $select->method('execute')->willReturn($statement);

        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willThrowException(new Exception('DB write error'));

        $this->database->method('select')->willReturn($select);
        $this->database->method('insert')->willReturn($insert);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unable to save the Apple Wallet Registration for @serial: @error.', [
                '@serial' => $serial,
                '@error' => 'DB write error',
            ]);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'POST'], json_encode(['pushToken' => 'push_xyz']));
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, $deviceLib, $passType, $serial);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationDeleteExceptionReturns500(): void
    {
        $serial = 'esncard-123';
        $deviceLib = 'device_abc';
        $passType = 'pass.com.example';

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willThrowException(new Exception('DB delete error'));

        $this->database->method('delete')->willReturn($delete);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unable to delete the Apple Wallet Registration for @serial: @error.', [
                '@serial' => $serial,
                '@error' => 'DB delete error',
            ]);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'DELETE']);
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, $deviceLib, $passType, $serial);
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleDeviceRegistrationMethodNotAllowedReturns405(): void
    {
        $serial = 'esncard-123';
        $deviceLib = 'device_abc';
        $passType = 'pass.com.example';

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], ['REQUEST_METHOD' => 'PUT']);
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->handleDeviceRegistration($request, $deviceLib, $passType, $serial);
        $this->assertEquals(405, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetUpdatablePassesDbExceptionReturns500(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willThrowException(new Exception('Select failed'));

        $this->database->method('select')->willReturn($select);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unable to retrieve the device passes for @device: @error.', [
                '@device' => 'device_abc',
                '@error' => 'Select failed',
            ]);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $response = $controller->getUpdatablePasses($request, 'device_abc', 'pass.com.example');
        $this->assertEquals(500, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetUpdatablePassesWithFreePassAndUnknownSerialsAndFallbacks(): void
    {
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchCol')->willReturn([
            'other_format_123',
            'free_pass-999',
            'free_pass-404',
            'esncard-888',
        ]);

        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($statement);

        $this->database->method('select')->willReturn($select);

        $pass999 = $this->createMock(ApplicationInterface::class);
        $pass999->method('getDateLastModified')->willReturn(null);
        $pass999->method('getDateCreated')->willReturn(new DrupalDateTime('2026-06-01 12:00:00'));

        $pass888 = $this->createMock(ApplicationInterface::class);
        $pass888->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-06-02 12:00:00'));

        $this->applicationStorage->method('load')
            ->willReturnCallback(function ($id) use ($pass999, $pass888) {
                return match ($id) {
                    '999' => $pass999,
                    '404' => null,
                    '888' => $pass888,
                    default => null,
                };
            });

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['passesUpdatedSince' => strtotime('2026-05-01')]);
        $response = $controller->getUpdatablePasses($request, 'device_abc', 'pass.com.example');
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertContains('free_pass-999', $data['serialNumbers']);
        $this->assertContains('esncard-888', $data['serialNumbers']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetUpdatablePassesNoneUpdatedReturns204(): void
    {
        $statement = $this->createMock(StatementInterface::class);
        $statement->method('fetchCol')->willReturn(['esncard-888']);

        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($statement);

        $this->database->method('select')->willReturn($select);

        $pass888 = $this->createMock(ApplicationInterface::class);
        $pass888->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-01-01'));

        $this->applicationStorage->method('load')->with('888')->willReturn($pass888);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['passesUpdatedSince' => strtotime('2026-06-01')]);
        $response = $controller->getUpdatablePasses($request, 'device_abc', 'pass.com.example');
        $this->assertEquals(204, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassInvalidAuthReturns401(): void
    {
        $passType = 'pass.com.example';
        $serial = 'esncard-123';

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', 'InvalidToken');

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(401, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassUnexpectedSerialStructureReturns400(): void
    {
        $passType = 'pass.com.example';
        $serial = 'bad_prefix_123';

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassApplicationNotFoundReturns404(): void
    {
        $passType = 'pass.com.example';
        $serial = 'esncard-404';

        $this->applicationStorage->method('load')->with('404')->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassCardMissingCardNumberReturns404(): void
    {
        $passType = 'pass.com.example';
        $serial = 'esncard-123';

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::ESNcardNumber)->willReturn(null);

        $this->applicationStorage->method('load')->with('123')->willReturn($app);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassFreePassSuccess(): void
    {
        $passType = 'pass.com.example';
        $serial = 'free_pass-123';
        $passToken = str_repeat('C', 32);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::PassToken)->willReturn($passToken);
        $app->method('getDateLastModified')->willReturn(new DrupalDateTime('2026-05-01'));

        $this->applicationStorage->method('load')->with('123')->willReturn($app);
        $this->applicationStorage->method('getByPassToken')->with($passToken)->willReturn($app);

        $this->appleWalletService->method('createFreePass')->with($app)->willReturn('FREE_PASS_BYTES');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('FREE_PASS_BYTES', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testGetLatestPassDownloadExceptionReturnsErrorResponse(): void
    {
        $passType = 'pass.com.example';
        $serial = 'free_pass-123';
        $passToken = str_repeat('C', 32);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::PassToken)->willReturn($passToken);

        $this->applicationStorage->method('load')->with('123')->willReturn($app);
        $this->applicationStorage->method('getByPassToken')->with($passToken)->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'apple_pass_type_id' => $passType,
            ],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request();
        $request->headers->set('Authorization', $this->getAuthHeader($serial));

        $response = $controller->getLatestPass($request, $passType, $serial);
        $this->assertEquals(404, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testLogMessageFloodNotAllowed(): void
    {
        $this->flood->expects($this->once())
            ->method('isAllowed')
            ->with('esn_membership_manager.apple_log', 5, 60)
            ->willReturn(false);

        $this->flood->expects($this->never())->method('register');
        $this->logger->expects($this->never())->method('error');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new AppleWalletController(
            $this->appleWalletService,
            $this->entityTypeManager,
            $this->database,
            $configFactory,
            $this->settings,
            $this->flood,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['logs' => ['device log message']]));
        $response = $controller->logMessage($request);

        $this->assertEquals(200, $response->getStatusCode());
    }
}
