<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Controller\DiditController;
use Drupal\esn_membership_manager\Service\DiditService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\esn_membership_manager\Controller\DiditController
 * @uses   \Drupal\esn_membership_manager\Utility\Nationalities
 * @group esn_membership_manager
 */
class DiditControllerTest extends MembershipManagerTestCase
{
    private ModuleHandlerInterface $moduleHandler;

    protected function setUp(): void
    {
        parent::setUp();

        $projectRoot = dirname(__DIR__, 4);
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn($projectRoot);

        $this->moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $this->moduleHandler->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($module);
        $this->container->set('module_handler', $this->moduleHandler);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')
            ->willReturnCallback(fn($name) => '/dummy/' . $name);
        $this->container->set('url_generator', $urlGenerator);
    }

    public function testCreate(): void
    {
        $database = $this->createMock(Connection::class);
        $diditService = $this->createMock(DiditService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) use ($database, $diditService, $loggerFactory) {
                return match ($id) {
                    'database' => $database,
                    'esn_membership_manager.didit_service' => $diditService,
                    'logger.factory' => $loggerFactory,
                    'module_handler' => $this->moduleHandler,
                    default => null,
                };
            });

        $controller = DiditController::create($container);
        $this->assertInstanceOf(DiditController::class, $controller);
    }

    public function testCallbackEmptySessionId(): void
    {
        $database = $this->createMock(Connection::class);
        $database->expects($this->never())->method('update');

        $diditService = $this->createMock(DiditService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Didit ID Verification failed. Session ID not present in query parameters.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request();

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.apply', $response->getTargetUrl());
    }

    public function testCallbackApplicationNotFound(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['didit_status' => 'Failed'])->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())->method('warning');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallbackGetSessionEmpty(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 1, 'didit_session_id' => 'sess_123']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['didit_status' => 'Failed'])->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $diditService->expects($this->once())
            ->method('getSession')
            ->with('sess_123')
            ->willReturn(null);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())->method('warning');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallbackSuccessApprovedWithGBRNationality(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 1, 'didit_session_id' => 'sess_123']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with([
                'didit_status' => 'Approved',
                'id_name' => 'John',
                'id_surname' => 'Doe',
                'id_nationality' => 'British',
                'id_dob' => '2000-01-01',
            ])
            ->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $diditService->expects($this->once())
            ->method('getSession')
            ->with('sess_123')
            ->willReturn([
                'status' => 'Approved',
                'id_verifications' => [
                    [
                        'nationality' => 'GBR',
                        'first_name' => 'John',
                        'last_name' => 'Doe',
                        'date_of_birth' => '2000-01-01',
                    ],
                ],
            ]);

        $loggerFactory = $this->getLoggerFactoryMock();

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.apply', $response->getTargetUrl());
    }

    public function testCallbackSuccessNonApprovedStatus(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 1, 'didit_session_id' => 'sess_123']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with(['didit_status' => 'Pending'])
            ->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $diditService->expects($this->once())
            ->method('getSession')
            ->with('sess_123')
            ->willReturn(['status' => 'Pending']);

        $loggerFactory = $this->getLoggerFactoryMock();

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallbackSelectException(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willThrowException(new Exception('Database down'));
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['didit_status' => 'Failed'])->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Didit ID Verification failed. @error',
                ['@error' => 'Unable to retrieve applications. Database down']
            );
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_err']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallbackUpdateException(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 1, 'didit_session_id' => 'sess_123']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $updateNormal = $this->createMock(Update::class);
        $updateNormal->method('fields')->willReturnSelf();
        $updateNormal->method('condition')->willReturnSelf();
        $updateNormal->method('execute')->willThrowException(new Exception('Lock timeout'));

        $updateFailed = $this->createMock(Update::class);
        $updateFailed->method('fields')->with(['didit_status' => 'Failed'])->willReturnSelf();
        $updateFailed->method('condition')->willReturnSelf();
        $updateFailed->expects($this->once())->method('execute');

        $database->method('update')->willReturnOnConsecutiveCalls($updateNormal, $updateFailed);

        $diditService = $this->createMock(DiditService::class);
        $diditService->method('getSession')->willReturn(['status' => 'Declined', 'id_verifications' => [[
            'nationality' => 'SHN',
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'date_of_birth' => '1999-09-09',
        ]]]);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Didit ID Verification failed. @error',
                ['@error' => 'Unable to get update application status. Lock timeout']
            );
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testSetFailedStatusUpdateExceptionLogsError(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willThrowException(new Exception('Fatal DB error'));
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())->method('warning');
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to update Didit status to Failed. @error', ['@error' => 'Fatal DB error']);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_123']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testCallbackWithDOMAndFallbackNationalities(): void
    {
        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 1, 'didit_session_id' => 'sess_dom']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with([
                'didit_status' => 'In Review',
                'id_name' => 'Carlos',
                'id_surname' => 'Santos',
                'id_nationality' => 'Dominican',
                'id_dob' => '2001-02-03',
            ])
            ->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $database->method('update')->willReturn($update);

        $diditService = $this->createMock(DiditService::class);
        $diditService->method('getSession')->willReturn([
            'status' => 'In Review',
            'id_verifications' => [[
                'nationality' => 'DOM',
                'first_name' => 'Carlos',
                'last_name' => 'Santos',
                'date_of_birth' => '2001-02-03',
            ]],
        ]);

        $loggerFactory = $this->getLoggerFactoryMock();

        $controller = new DiditController($database, $diditService, $loggerFactory);
        $request = new Request(['verificationSessionId' => 'sess_dom']);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }
}
