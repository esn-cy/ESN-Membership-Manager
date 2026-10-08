<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultForbidden;
use Drupal\Core\Action\ActionInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\esn_membership_manager\Controller\StatusController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\esn_membership_manager\Controller\StatusController
 * @group esn_membership_manager
 */
class StatusControllerTest extends MembershipManagerTestCase
{
    private ActionManager $actionManager;
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private LoggerChannelInterface $logger;
    private AccountProxyInterface $currentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actionManager = $this->createMock(ActionManager::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($this->applicationStorage);

        $this->logger = $this->createMock(LoggerChannelInterface::class);

        $this->currentUser = $this->createMock(AccountProxyInterface::class);
        $this->container->set('current_user', $this->currentUser);
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
                    'plugin.manager.action' => $this->actionManager,
                    'entity_type.manager' => $this->entityTypeManager,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = StatusController::create($container);
        $this->assertInstanceOf(StatusController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testMissingParametersReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('The request was missing required parameters.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testInvalidRejectedFormatReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Rejected-invalid_format_without_double_hyphen',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Invalid rejection reason format.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testInvalidStatusFormatReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved ExtraWords',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Invalid status format provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testUnknownStatusReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'UnknownStatus',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid status was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testInvalidIdentifierFormatReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => 'not_a_valid_card',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid card number was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testNonESNcardIdentifierWithPaidStatusReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        // Pass token (not ESNcard) with 'Paid' status (which is in PaidStatuses)
        $passToken = str_repeat('A', 32);
        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => $passToken,
            'status' => 'Paid',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Action not allowed with this kind of identifier.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testInvalidNonNumericApplicationIdReturns400(): void
    {
        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => 'abc_not_numeric',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('An invalid ID was provided.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testApplicationNotFoundReturns404(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn(null);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testNonESNcardApplicationWithPaidStatusReturns400(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Paid',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(400, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Action not allowed for this application.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testActionPluginNotFoundReturns500(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(false);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Action plugin not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testActionAccessDeniedReturns403(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultForbidden());

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(403, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('You do not have permission to perform this action.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusSuccessWithApplicationId(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('123');
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultAllowed());

        $action->expects($this->once())
            ->method('execute')
            ->with($app);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('The status of the application has been updated.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusRejectedSuccessPassingFullStatus(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('123');
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_reject')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultAllowed());

        $rejectionStatus = 'Rejected-reason-one';
        $action->expects($this->once())
            ->method('execute')
            ->with($app, $rejectionStatus);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_reject')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => $rejectionStatus,
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('The status of the application has been updated.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testActionExecuteThrowsExceptionReturns500(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('123');
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultAllowed());

        $action->expects($this->once())
            ->method('execute')
            ->with($app)
            ->willThrowException(new Exception('Payment sync failed'));

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Payment sync failed', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusSuccessWithESNcardNumber(): void
    {
        $cardNumber = '1234567ABCD';
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('456');
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultAllowed());

        $action->expects($this->once())
            ->method('execute')
            ->with($app);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => $cardNumber,
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('The status of the application has been updated.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusESNcardNotFoundReturns404(): void
    {
        $cardNumber = '1234567ABCD';
        $this->applicationStorage->expects($this->once())
            ->method('getByESNcard')
            ->with($cardNumber)
            ->willReturn(null);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => $cardNumber,
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusSuccessWithPassToken(): void
    {
        $passToken = str_repeat('B', 32);
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('789');
        $app->method('getValue')->with(ApplicationField::HasESNcard)->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn($app);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $action = $this->createMock(ActionInterface::class);
        $action->expects($this->once())
            ->method('access')
            ->with(null, $this->currentUser, true)
            ->willReturn(new AccessResultAllowed());

        $action->expects($this->once())
            ->method('execute')
            ->with($app);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => $passToken,
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('The status of the application has been updated.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusPassTokenNotFoundReturns404(): void
    {
        $passToken = str_repeat('B', 32);
        $this->applicationStorage->expects($this->once())
            ->method('getByPassToken')
            ->with($passToken)
            ->willReturn(null);

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'card' => $passToken,
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(404, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Application not found.', $data['message']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testChangeStatusStorageExceptionReturns500(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('123')
            ->willThrowException(new Exception('Database connection failed'));

        $controller = new StatusController(
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request([], [], [], [], [], [], json_encode([
            'id' => '123',
            'status' => 'Approved',
        ]));
        $response = $controller->changeStatus($request);
        $this->assertEquals(500, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals('Database connection failed', $data['message']);
    }
}

