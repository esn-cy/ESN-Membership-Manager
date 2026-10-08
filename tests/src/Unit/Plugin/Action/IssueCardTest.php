<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Mail\CardIssuanceEmail;
use Drupal\esn_membership_manager\Plugin\Action\IssueCard;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for IssueCard action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\IssueCard
 * @uses         \Drupal\esn_membership_manager\Mail\CardIssuanceEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class IssueCardTest extends MembershipManagerTestCase
{
    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['omnia.email_service', 1, $this->createMock(EmailService::class)],
            ['logger.factory', 1, $this->getLoggerFactoryMock()],
        ]);

        $action = IssueCard::create($container, [], 'esn_membership_manager_issue', []);
        $this->assertInstanceOf(IssueCard::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new IssueCard([], 'issue_id', [], $emailService, $this->getLoggerFactoryMock());
        $action->execute();
        $this->assertTrue(true);
    }

    public function testExecuteThrowsWhenStatusCannotBeApplied(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('101');
        $app->method('addApprovalStatus')
            ->with(ApprovalStatuses::Issued)
            ->willReturn('This status has been applied out of order.');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id cannot be marked as issued. @issues.', [
                '@id' => '101',
                '@issues' => 'This status has been applied out of order.',
            ]);

        $action = new IssueCard([], 'issue_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This status cannot be applied.');

        $action->execute($app);
    }

    public function testExecuteHandlesSaveException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('102');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Issued)->willReturn(true);
        $app->method('save')->willThrowException(new Exception('Database save error'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unable to mark card as issued @id: @message', [
                '@id' => '102',
                '@message' => 'Database save error',
            ]);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new IssueCard([], 'issue_id', [], $emailService, $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to complete issuance process');

        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessSendsEmailAndLogsNotice(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('103');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Issued)->willReturn(true);
        $app->expects($this->once())->method('save');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Name => 'Elena',
                ApplicationField::Email => 'elena@example.com',
                default => null,
            };
        });

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Issued application @id', ['@id' => '103']);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'elena@example.com',
                $this->callback(function (CardIssuanceEmail $email) {
                    $this->assertSame('Elena', $email->getVariables()['name']);
                    return true;
                })
            );

        $action = new IssueCard([], 'issue_id', [], $emailService, $this->getLoggerFactoryMock($logger));
        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new IssueCard([], 'issue_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock());

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('issue cards')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('issue cards')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
