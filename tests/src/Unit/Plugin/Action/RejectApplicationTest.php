<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Mail\RejectionEmail;
use Drupal\esn_membership_manager\Plugin\Action\RejectApplication;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for RejectApplication action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\RejectApplication
 * @uses         \Drupal\esn_membership_manager\Mail\RejectionEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class RejectApplicationTest extends MembershipManagerTestCase
{
    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['omnia.email_service', 1, $this->createMock(EmailService::class)],
            ['logger.factory', 1, $this->getLoggerFactoryMock()],
        ]);

        $action = RejectApplication::create($container, [], 'esn_membership_manager_reject', []);
        $this->assertInstanceOf(RejectApplication::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new RejectApplication([], 'reject_id', [], $emailService, $this->getLoggerFactoryMock());
        $action->execute();
        $this->assertTrue(true);
    }

    public function testExecuteThrowsWhenStatusCannotBeApplied(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('401');
        $app->method('addApprovalStatus')
            ->with(ApprovalStatuses::Rejected)
            ->willReturn('This application already been approved or rejected.');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id cannot be marked as rejected. @issues.', [
                '@id' => '401',
                '@issues' => 'This application already been approved or rejected.',
            ]);

        $action = new RejectApplication([], 'reject_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This status cannot be applied.');

        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteDefaultRejectionWithoutSpecificReasons(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('402');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Rejected)->willReturn(true);
        $app->expects($this->once())->method('clearPendingStatuses');
        $app->expects($this->once())->method('save');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'Anna',
                ApplicationField::Email => 'anna@example.com',
                default => null,
            };
        });

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Rejected application @id', ['@id' => '402']);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'anna@example.com',
                $this->callback(function (RejectionEmail $email) {
                    $vars = $email->getVariables();
                    $this->assertSame('Anna', $vars['name']);
                    $this->assertSame([], $vars['reasons']);
                    return true;
                })
            );

        $action = new RejectApplication([], 'reject_id', [], $emailService, $this->getLoggerFactoryMock($logger));
        // Test with null and 'Rejected' reasons
        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithFormattedCompoundReasons(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('403');

        $reasons = 'Rejected-Status-Local/Rejected-Identity-Duplicate/Rejected-Photo-Blurry/Ignored-Non-Rejected';
        $app->method('addApprovalStatus')->with($reasons)->willReturn(true);
        $app->expects($this->once())->method('clearPendingStatuses');
        $app->expects($this->once())->method('save');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'Dimitris',
                ApplicationField::Email => 'dimitris@example.com',
                default => null,
            };
        });

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'dimitris@example.com',
                $this->callback(function (RejectionEmail $email) {
                    $vars = $email->getVariables();
                    $this->assertSame('Dimitris', $vars['name']);
                    $expectedReasons = [
                        'Status Document: Local Student',
                        'Identity Document: Duplicate Application',
                        'Photo: Blurry',
                    ];
                    $this->assertSame($expectedReasons, $vars['reasons']);
                    return true;
                })
            );

        $action = new RejectApplication([], 'reject_id', [], $emailService, $this->getLoggerFactoryMock());
        $action->execute($app, $reasons);
    }

    public function testExecuteHandlesSaveException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('404');
        $app->method('addApprovalStatus')->willReturn(true);
        $app->method('save')->willThrowException(new Exception('Save failed'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unable to reject application @id: @message', [
                '@id' => '404',
                '@message' => 'Save failed',
            ]);

        $action = new RejectApplication([], 'reject_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to complete rejection process');

        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new RejectApplication([], 'reject_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock());

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('reject applications')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('reject applications')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
