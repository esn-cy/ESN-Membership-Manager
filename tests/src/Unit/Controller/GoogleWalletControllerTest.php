<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\esn_membership_manager\Controller\GoogleWalletController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers \Drupal\esn_membership_manager\Controller\GoogleWalletController
 * @group esn_membership_manager
 */
class GoogleWalletControllerTest extends MembershipManagerTestCase
{
    private GoogleService $googleService;
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private GuestPassStorage $guestPassStorage;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->googleService = $this->createMock(GoogleService::class);
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

        $this->logger = $this->createMock(LoggerChannelInterface::class);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $loggerFactory = $this->getLoggerFactoryMock($this->logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) use ($loggerFactory) {
                return match ($id) {
                    'esn_membership_manager.google_service' => $this->googleService,
                    'entity_type.manager' => $this->entityTypeManager,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = GoogleWalletController::create($container);
        $this->assertInstanceOf(GoogleWalletController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletEmptyIdentifierThrows400(): void
    {
        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('No identifier was provided.');
        $controller->addToWallet('');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletInvalidIdentifierThrows400(): void
    {
        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('An invalid identifier was provided.');
        $controller->addToWallet('invalid_identifier');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletESNcardNotFoundThrows404(): void
    {
        // Valid ESNcard format: 7 digits + 3 uppercase letters + 1 uppercase or digit
        $cardNumber = '1234567ABCD';
        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn(null);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No application was found.');
        $controller->addToWallet($cardNumber);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletPassNotFoundThrows404(): void
    {
        // 32-char hex pass token
        $passToken = str_repeat('A', 32);
        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn(null);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('No application was found.');
        $controller->addToWallet($passToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletESNcardSuccess(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->googleService->expects($this->once())
            ->method('getESNcardObject')
            ->with($app)
            ->willReturn('https://pay.google.com/gp/v/save/jwt_esncard');

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->addToWallet($cardNumber);
        $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
        $this->assertEquals('https://pay.google.com/gp/v/save/jwt_esncard', $response->getTargetUrl());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletPassSuccess(): void
    {
        $passToken = str_repeat('B', 32);
        $app = $this->createMock(ApplicationInterface::class);

        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn($app);

        $this->googleService->expects($this->once())
            ->method('getFreePassObject')
            ->with($app)
            ->willReturn('https://pay.google.com/gp/v/save/jwt_freepass');

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->addToWallet($passToken);
        $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
        $this->assertEquals('https://pay.google.com/gp/v/save/jwt_freepass', $response->getTargetUrl());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddToWalletGenerationFailureThrows500(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->googleService->expects($this->once())
            ->method('getESNcardObject')
            ->with($app)
            ->willThrowException(new Exception('Google API error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Creation of Google Wallet Pass failed: @message', ['@message' => 'Google API error']);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unable to generate your Google Wallet Pass.');
        $controller->addToWallet($cardNumber);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddGuestPassNotFoundThrows404(): void
    {
        // Guest token: GUEST + 27 hex chars
        $guestToken = 'GUEST' . str_repeat('F', 27);

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn(null);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Guest Pass not found.');
        $controller->addToWallet($guestToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddGuestReferrerNotFoundThrows404(): void
    {
        $guestToken = 'GUEST' . str_repeat('E', 27);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn(null);

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Guest Pass not found.');
        $controller->addToWallet($guestToken);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddGuestSuccess(): void
    {
        $guestToken = 'GUEST' . str_repeat('D', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->googleService->expects($this->once())
            ->method('getGuestPassObject')
            ->with($guestPass, $referer)
            ->willReturn('https://pay.google.com/gp/v/save/jwt_guest');

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->addToWallet($guestToken);
        $this->assertInstanceOf(TrustedRedirectResponse::class, $response);
        $this->assertEquals('https://pay.google.com/gp/v/save/jwt_guest', $response->getTargetUrl());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddGuestServiceExceptionThrows500(): void
    {
        $guestToken = 'GUEST' . str_repeat('D', 27);
        $referer = $this->createMock(ApplicationInterface::class);
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('getReferer')->willReturn($referer);

        $this->guestPassStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($guestToken)
            ->willReturn($guestPass);

        $this->googleService->expects($this->once())
            ->method('getGuestPassObject')
            ->with($guestPass, $referer)
            ->willThrowException(new Exception('Google guest error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Creation of Google Wallet Pass failed: @message', ['@message' => 'Google guest error']);

        $controller = new GoogleWalletController(
            $this->googleService,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Unable to generate your Google Wallet Pass.');
        $controller->addToWallet($guestToken);
    }
}
