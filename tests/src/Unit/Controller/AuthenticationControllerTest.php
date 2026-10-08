<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Controller\AuthenticationController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Mail\AuthenticationEmail;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\AuthenticationController
 * @uses         \Drupal\esn_membership_manager\Mail\AuthenticationEmail
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class AuthenticationControllerTest extends MembershipManagerTestCase
{
    private Connection $database;
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private FloodInterface $flood;
    private EmailService $emailService;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(Connection::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($this->applicationStorage);

        $this->flood = $this->createMock(FloodInterface::class);
        $this->emailService = $this->createMock(EmailService::class);
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
                    'database' => $this->database,
                    'entity_type.manager' => $this->entityTypeManager,
                    'flood' => $this->flood,
                    'omnia.email_service' => $this->emailService,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = AuthenticationController::create($container);
        $this->assertInstanceOf(AuthenticationController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestInvalidTypeReturns400(): void
    {
        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'test@example.com']));
        $response = $controller->request($request, 'invalid_type');

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Invalid authentication type.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestMissingEmailReturns400(): void
    {
        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->request($request, 'login');

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Email is required.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestLoginUserNotExistsReturns200WithoutSendingEmail(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('unknown@example.com')
            ->willReturn(0);

        $this->emailService->expects($this->never())->method('send');

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'unknown@example.com']));
        $response = $controller->request($request, 'login');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('A code was sent to your email address.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestFloodBlockedReturns429(): void
    {
        $this->applicationStorage->method('countByEmail')->willReturn(1);

        $this->flood->expects($this->once())
            ->method('isAllowed')
            ->with('esn_membership_manager.auth_request_login', 3, 3600, 'blocked@example.com')
            ->willReturn(false);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'blocked@example.com']));
        $response = $controller->request($request, 'login');

        $this->assertEquals(429, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('You have made too many attempts. Please try again later.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestSuccess(): void
    {
        $email = 'user@example.com';
        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->method('fields')->willReturnSelf();
        $merge->expects($this->once())->method('execute');
        $this->database->method('merge')->with('esn_membership_manager_authentication')->willReturn($merge);

        $this->flood->method('isAllowed')->willReturn(true);
        $this->flood->expects($this->once())
            ->method('register')
            ->with('esn_membership_manager.auth_request_register', 3600, $email);

        $this->emailService->expects($this->once())
            ->method('send')
            ->with($email, $this->isInstanceOf(AuthenticationEmail::class));

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email]));
        $response = $controller->request($request, 'register');

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertStringContainsString('A code was sent to your email address', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyMissingFieldsReturns400(): void
    {
        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'user@example.com']));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Email and code are required.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyInvalidTypeReturns400(): void
    {
        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'user@example.com', 'code' => '12345678']));
        $response = $controller->verify($request, 'invalid_type');

        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Invalid authentication type.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyFloodBlockedReturns429(): void
    {
        $email = 'blocked@example.com';

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->expects($this->once())->method('execute');
        $this->database->method('delete')->with('esn_membership_manager_authentication')->willReturn($delete);

        $this->flood->expects($this->once())
            ->method('isAllowed')
            ->with('esn_membership_manager.auth_verify_login', 5, 3600, $email)
            ->willReturn(false);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => '12345678']));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(429, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('You have made too many attempts. Please try again later.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyCodeMismatchOrExpiredReturns401(): void
    {
        $email = 'user@example.com';

        $this->flood->method('isAllowed')->willReturn(true);
        $this->flood->expects($this->once())
            ->method('register')
            ->with('esn_membership_manager.auth_verify_login', 3600, $email);

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn([
            'code' => '99999999',
            'expires_at' => time() + 300,
        ]);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => '12345678']));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(401, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Invalid or expired code.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifySuccess(): void
    {
        $email = 'user@example.com';
        $code = '12345678';

        $this->flood->method('isAllowed')->willReturn(true);
        $this->flood->expects($this->once())
            ->method('clear')
            ->with('esn_membership_manager.auth_verify_login', $email);

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn([
            'code' => $code,
            'expires_at' => time() + 300,
        ]);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->expects($this->once())->method('execute');
        $this->database->method('delete')->with('esn_membership_manager_authentication')->willReturn($delete);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $session = new Session(new MockArraySessionStorage());
        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => $code]));
        $request->setSession($session);

        $response = $controller->verify($request, 'login');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($email, $session->get('login_verified_email'));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestDatabaseMergeExceptionReturns500(): void
    {
        $email = 'user@example.com';

        $this->applicationStorage->method('countByEmail')->with($email)->willReturn(1);
        $this->flood->method('isAllowed')->willReturn(true);

        $merge = $this->createMock(Merge::class);
        $merge->method('keys')->willReturnSelf();
        $merge->method('fields')->willReturnSelf();
        $merge->method('execute')->willThrowException(new Exception('DB write failure'));

        $this->database->method('merge')->with('esn_membership_manager_authentication')->willReturn($merge);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Authentication code creation failed: @message', ['@message' => 'DB write failure']);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email]));
        $response = $controller->request($request, 'login');

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('There was an issue while processing your request. Please try again later.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyFloodLimitDeleteExceptionIgnored(): void
    {
        $email = 'user@example.com';

        $this->flood->method('isAllowed')->willReturn(false);

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willThrowException(new Exception('Delete error'));
        $this->database->method('delete')->willReturn($delete);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => '12345678']));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(429, $response->getStatusCode());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyDatabaseSelectExceptionReturns500(): void
    {
        $email = 'user@example.com';

        $this->flood->method('isAllowed')->willReturn(true);

        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willThrowException(new Exception('Select error'));
        $this->database->method('select')->willReturn($select);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Authentication code verification failed: @message', ['@message' => 'Select error']);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => '12345678']));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('There was an issue while processing your request. Please try again later.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testVerifyDatabaseDeleteExceptionReturns500(): void
    {
        $email = 'user@example.com';
        $code = '12345678';

        $this->flood->method('isAllowed')->willReturn(true);

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn([
            'code' => $code,
            'expires_at' => time() + 300,
        ]);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();
        $delete->method('execute')->willThrowException(new Exception('Delete post-verify error'));
        $this->database->method('delete')->willReturn($delete);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Authentication code verification failed: @message', ['@message' => 'Delete post-verify error']);

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode(['email' => $email, 'code' => $code]));
        $response = $controller->verify($request, 'login');

        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('There was an issue while processing your request. Please try again later.', $data['error']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestRandomIntFailureUsesFallbackCode(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('user@example.com')
            ->willReturn(1);

        $this->flood->expects($this->once())
            ->method('isAllowed')
            ->willReturn(true);

        $this->flood->expects($this->once())
            ->method('register');

        $merge = $this->createMock(Merge::class);
        $merge->expects($this->once())->method('keys')->willReturnSelf();
        $merge->expects($this->once())->method('fields')->with($this->callback(function ($fields) {
            return strlen($fields['code']) === 8;
        }))->willReturnSelf();
        $merge->expects($this->once())->method('execute')->willReturn(1);

        $this->database->expects($this->once())
            ->method('merge')
            ->with('esn_membership_manager_authentication')
            ->willReturn($merge);

        $this->emailService->expects($this->once())
            ->method('send')
            ->with('user@example.com', $this->isInstanceOf(AuthenticationEmail::class));

        $controller = new AuthenticationController(
            $this->database,
            $this->entityTypeManager,
            $this->flood,
            $this->emailService,
            $this->getLoggerFactoryMock($this->logger)
        );

        $GLOBALS['fail_random_int'] = true;
        try {
            $request = new Request([], [], [], [], [], [], json_encode(['email' => 'user@example.com']));
            $response = $controller->request($request, 'login');
            $this->assertEquals(200, $response->getStatusCode());
        } finally {
            unset($GLOBALS['fail_random_int']);
        }
    }
}
