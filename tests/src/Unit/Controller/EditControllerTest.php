<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\ConditionInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Controller\EditController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\EditController
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class EditControllerTest extends MembershipManagerTestCase
{
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private FileService $fileService;
    private Connection $database;
    private GoogleService $googleService;
    private AppleWalletService $appleWalletService;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($this->applicationStorage);

        $this->fileService = $this->createMock(FileService::class);
        $this->database = $this->createMock(Connection::class);
        $this->googleService = $this->createMock(GoogleService::class);
        $this->appleWalletService = $this->createMock(AppleWalletService::class);
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
                    'database' => $this->database,
                    'esn_membership_manager.google_service' => $this->googleService,
                    'esn_membership_manager.apple_wallet_service' => $this->appleWalletService,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = EditController::create($container);
        $this->assertInstanceOf(EditController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationEmptyIdReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->editApplication($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('No ID was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationInvalidIdReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => 'abc']));
        $response = $controller->editApplication($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid ID was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationNotFoundReturns404(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123']));
        $response = $controller->editApplication($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationSuccess(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::HasVerifiedEmail, ApplicationField::HasVerifiedID, ApplicationField::HasVerifiedStatus => false,
                ApplicationField::HasESNcard => true,
                default => null,
            };
        });

        $app->expects($this->atLeastOnce())->method('setValue');
        $app->expects($this->once())->method('save');
        $app->method('isApproved')->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            ApplicationField::Name->value => 'Jane',
            ApplicationField::DateOfBirth->value => '15/08/2001',
        ]));
        $response = $controller->editApplication($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application updated successfully.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationSaveThrows500(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturn(false);
        $app->method('save')->willThrowException(new Exception('DB lock'));

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Update query failed: @message', ['@message' => 'DB lock']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123']));
        $response = $controller->editApplication($request);

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('There was a problem updating the application.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoMissingIdReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('No ID was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoMissingImageReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123']));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('No cropped image was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoInvalidBase64FormatReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => 'not_base64_data']));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid cropped image was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoFailedToDecodeImageReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => 'data:image/jpeg;base64,!!!invalid base64!!!']));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Failed to decode image.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoApplicationNotFoundReturns404(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn(null);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoNoFacePhotoReturns404(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFacePhoto')->willReturn(null);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('No face photo was found for this application.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoReplaceFileDataFailsReturns500(): void
    {
        $photo = $this->createMock(FileInterface::class);
        $photo->method('id')->willReturn('45');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFacePhoto')->willReturn($photo);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->fileService->expects($this->once())
            ->method('replaceFileData')
            ->with('45', 'fake_image_bytes')
            ->willReturn(false);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Unable to write file.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoSuccess(): void
    {
        $photo = $this->createMock(FileInterface::class);
        $photo->method('id')->willReturn('45');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFacePhoto')->willReturn($photo);
        $app->method('isApproved')->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->fileService->expects($this->once())
            ->method('replaceFileData')
            ->with('45', 'fake_image_bytes')
            ->willReturn(true);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Photo cropped successfully.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationWalletSyncApprovedAndThrowsExceptions(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('isApproved')->willReturn(true);
        $app->method('id')->willReturn(99);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::HasESNcard => true,
                default => false,
            };
        });

        $this->applicationStorage->method('load')->with('99')->willReturn($app);
        $app->expects($this->once())->method('save');

        $this->googleService->method('updateApplicationObject')
            ->willThrowException(new Exception('Google API down'));

        $this->database->method('select')
            ->willThrowException(new Exception('DB apple select error'));

        $this->logger->expects($this->exactly(3))->method('error');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => true,
                'switch_apple_wallet' => true,
            ],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '99']));
        $response = $controller->editApplication($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testEditApplicationWalletSyncAppleSuccess(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('isApproved')->willReturn(true);
        $app->method('id')->willReturn(99);
        $app->method('getValue')->willReturnCallback(function () {
            return false;
        });

        $this->applicationStorage->method('load')->with('99')->willReturn($app);

        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchCol')->willReturn(['token_1', 'token_2']);

        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        $orCondition = $this->createMock(ConditionInterface::class);
        $orCondition->method('condition')->willReturnSelf();
        $select->method('orConditionGroup')->willReturn($orCondition);

        $this->database->method('select')->willReturn($select);

        $this->appleWalletService->expects($this->exactly(2))
            ->method('sendApplicationUpdateNotification');

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => false,
                'switch_apple_wallet' => true,
            ],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => '99']));
        $response = $controller->editApplication($request);
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoInvalidNonNumericIdReturns400(): void
    {
        $configFactory = $this->getConfigFactoryStub([MembershipSettings::CONFIG_NAME => []]);
        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['id' => 'not_numeric']));
        $response = $controller->cropPhoto($request);
        $this->assertEquals(400, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoApprovedWithWalletUpdatesAndCatches(): void
    {
        $photo = $this->createMock(FileInterface::class);
        $photo->method('id')->willReturn('45');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFacePhoto')->willReturn($photo);
        $app->method('isApproved')->willReturn(true);

        $this->applicationStorage->method('load')->with('123')->willReturn($app);
        $this->fileService->method('replaceFileData')->willReturn(true);

        $this->googleService->method('updateApplicationObject')
            ->willThrowException(new Exception('Google crop err'));

        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchCol')->willReturn(['token_crop_1']);

        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $this->appleWalletService->expects($this->once())
            ->method('sendApplicationUpdateNotification')
            ->with('token_crop_1');

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Google Wallet Update Failed: @message', ['@message' => 'Google crop err']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => true,
                'switch_apple_wallet' => true,
            ],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCropPhotoAppleSelectThrowsExceptionLogsError(): void
    {
        $photo = $this->createMock(FileInterface::class);
        $photo->method('id')->willReturn('45');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFacePhoto')->willReturn($photo);
        $app->method('isApproved')->willReturn(true);

        $this->applicationStorage->method('load')->with('123')->willReturn($app);
        $this->fileService->method('replaceFileData')->willReturn(true);

        $this->database->method('select')
            ->willThrowException(new Exception('DB crop err'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Apple Wallet Update Failed: @message', ['@message' => 'DB crop err']);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_google_wallet' => false,
                'switch_apple_wallet' => true,
            ],
        ]);

        $controller = new EditController(
            $this->entityTypeManager,
            $configFactory,
            $this->fileService,
            $this->database,
            $this->googleService,
            $this->appleWalletService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $base64 = 'data:image/png;base64,' . base64_encode('fake_image_bytes');
        $request = new Request([], [], [], [], [], [], json_encode(['id' => '123', 'image' => $base64]));
        $response = $controller->cropPhoto($request);

        $this->assertEquals(200, $response->getStatusCode());
    }
}
