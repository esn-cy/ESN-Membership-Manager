<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use BackedEnum;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Plugin\Action\MarkApplicationAsPaid;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for MarkApplicationAsPaid action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\MarkApplicationAsPaid
 * @uses         \Drupal\esn_membership_manager\Utility\ApprovalStatuses
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class MarkApplicationAsPaidTest extends MembershipManagerTestCase
{
    private Connection $database;
    private ESNcardService $esncardService;
    private StripeService $stripeService;
    private LockBackendInterface $lock;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(Connection::class);
        $this->esncardService = $this->createMock(ESNcardService::class);
        $this->stripeService = $this->createMock(StripeService::class);
        $this->lock = $this->createMock(LockBackendInterface::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
    }

    private function createAction(): MarkApplicationAsPaid
    {
        return new MarkApplicationAsPaid(
            [],
            'esn_membership_manager_mark_paid',
            [],
            $this->database,
            $this->esncardService,
            $this->stripeService,
            $this->lock,
            $this->getLoggerFactoryMock($this->logger)
        );
    }

    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['database', 1, $this->database],
            ['esn_membership_manager.esncard_service', 1, $this->esncardService],
            ['esn_membership_manager.stripe_service', 1, $this->stripeService],
            ['lock', 1, $this->lock],
            ['logger.factory', 1, $this->getLoggerFactoryMock($this->logger)],
        ]);

        $action = MarkApplicationAsPaid::create($container, [], 'esn_membership_manager_mark_paid', []);
        $this->assertInstanceOf(MarkApplicationAsPaid::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithNullApplicationLogsWarningAndReturnsString(): void
    {
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Mark Application as Paid executed without a valid Application.');

        $action = $this->createAction();
        $result = $action->execute();

        $this->assertSame('Did not run due to an empty Application', $result);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWhenLockCannotBeAcquiredLogsWarningAndReturnsString(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(false);

        $this->lock->expects($this->never())->method('release');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Could not acquire lock for application @id. Another process may be running.',
                ['@id' => '42']
            );

        $action = $this->createAction();
        $result = $action->execute($application);

        $this->assertSame('Did not run due to an error acquiring a lock', $result);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWhenApplicationAlreadyPaidLogsWarningReleasesLockAndReturnsString(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')
            ->with(ApplicationField::ESNcardNumber)
            ->willReturn('12345678');
        $application->method('isPaid')->willReturn(true);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Application @id was already paid. Duplicate payment event detected.',
                ['@id' => '42']
            );

        $action = $this->createAction();
        $result = $action->execute($application);

        $this->assertSame('Duplicate payment event detected', $result);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWhenAddApprovalStatusReturnsErrorStringLogsWarningReleasesLockAndReturnsString(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')
            ->with(ApplicationField::ESNcardNumber)
            ->willReturn(null);
        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn('Status conflict: already rejected');

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Application @id cannot be marked as paid. @issues.',
                [
                    '@id' => '42',
                    '@issues' => 'Status conflict: already rejected',
                ]
            );

        $action = $this->createAction();
        $result = $action->execute($application);

        $this->assertSame('This status cannot be applied.', $result);
    }

    public function testExecuteWhenAssignESNcardNumberThrowsExceptionRollsBackReleasesLockAndRethrows(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')
            ->with(ApplicationField::ESNcardNumber)
            ->willReturn('');
        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn(true);

        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())->method('rollBack');

        $this->database->expects($this->once())
            ->method('startTransaction')
            ->willReturn($transaction);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->esncardService->expects($this->once())
            ->method('assignESNcardNumber')
            ->with($application, true)
            ->willThrowException(new Exception('No card numbers left in pool'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to assign an ESNcard number to application @id: @message',
                ['@id' => '42', '@message' => 'No card numbers left in pool']
            );

        $action = $this->createAction();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to assign an ESNcard number');

        $action->execute($application);
    }

    public function testExecuteWhenApplicationSaveThrowsExceptionRollsBackReleasesLockAndRethrows(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')
            ->with(ApplicationField::ESNcardNumber)
            ->willReturn(null);
        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn(true);

        $matcher = $this->exactly(2);
        $application->expects($matcher)
            ->method('setValue')
            ->willReturnCallback(function ($field, $value) use ($matcher, $application) {
                match ($matcher->getInvocationCount()) {
                    1 => [$this->assertSame(ApplicationField::DatePaid, $field), $this->assertIsString($value)],
                    2 => [$this->assertSame(ApplicationField::ESNcardNumber, $field), $this->assertSame('12345678', $value)],
                };
                return $application;
            });

        $application->expects($this->once())
            ->method('save')
            ->willThrowException(new Exception('Storage connection failure'));

        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())->method('rollBack');

        $this->database->expects($this->once())
            ->method('startTransaction')
            ->willReturn($transaction);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->esncardService->expects($this->once())
            ->method('assignESNcardNumber')
            ->with($application, true)
            ->willReturn('12345678');

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to update application @id: @message',
                ['@id' => '42', '@message' => 'Storage connection failure']
            );

        $action = $this->createAction();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to update application');

        $action->execute($application);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithoutPaymentLink(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')->willReturnCallback(function () {
            return null;
        });
        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn(true);

        $matcher = $this->exactly(2);
        $application->expects($matcher)
            ->method('setValue')
            ->willReturnCallback(function ($field, $value) use ($matcher, $application) {
                match ($matcher->getInvocationCount()) {
                    1 => [$this->assertSame(ApplicationField::DatePaid, $field), $this->assertIsString($value)],
                    2 => [$this->assertSame(ApplicationField::ESNcardNumber, $field), $this->assertSame('87654321', $value)],
                };
                return $application;
            });

        $application->expects($this->once())->method('save');

        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->never())->method('rollBack');

        $this->database->expects($this->once())
            ->method('startTransaction')
            ->willReturn($transaction);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->esncardService->expects($this->once())
            ->method('assignESNcardNumber')
            ->with($application, false)
            ->willReturn('87654321');

        $this->esncardService->expects($this->once())
            ->method('postAssignment')
            ->with($application, false);

        $this->stripeService->expects($this->never())->method('disablePaymentLink');

        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                'Application @id marked as Paid and assigned ESNcard number.',
                ['@id' => '42']
            );

        $action = $this->createAction();
        $result = $action->execute($application, false);

        $this->assertSame('ESNcard payment was processed successfully', $result);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithPaymentLinkAndDisablingSucceeds(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');

        // Test branch where ESNcardNumber is non-empty but isPaid() is false
        $application->method('getValue')->willReturnCallback(function ($field) {
            $key = $field instanceof BackedEnum ? $field->value : (string)$field;
            return match ($key) {
                ApplicationField::ESNcardNumber->value => 'old_card_123',
                ApplicationField::PaymentLinkID->value => 'plink_abc123',
                default => null,
            };
        });
        $application->method('isPaid')->willReturn(false);

        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn(true);

        $matcher = $this->exactly(2);
        $application->expects($matcher)
            ->method('setValue')
            ->willReturnCallback(function ($field, $value) use ($matcher, $application) {
                match ($matcher->getInvocationCount()) {
                    1 => [$this->assertSame(ApplicationField::DatePaid, $field), $this->assertIsString($value)],
                    2 => [$this->assertSame(ApplicationField::ESNcardNumber, $field), $this->assertSame('99887766', $value)],
                };
                return $application;
            });

        $application->expects($this->once())->method('save');

        $transaction = $this->createMock(Transaction::class);
        $this->database->expects($this->once())
            ->method('startTransaction')
            ->willReturn($transaction);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->esncardService->expects($this->once())
            ->method('assignESNcardNumber')
            ->with($application, true)
            ->willReturn('99887766');

        $this->stripeService->expects($this->once())
            ->method('disablePaymentLink')
            ->with('plink_abc123');

        $this->esncardService->expects($this->once())
            ->method('postAssignment')
            ->with($application, true);

        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                'Application @id marked as Paid and assigned ESNcard number.',
                ['@id' => '42']
            );

        $action = $this->createAction();
        $result = $action->execute($application);

        $this->assertSame('ESNcard payment was processed successfully', $result);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWhenDisablePaymentLinkThrowsExceptionLogsErrorAndContinues(): void
    {
        $application = $this->createMock(ApplicationInterface::class);
        $application->method('id')->willReturn('42');
        $application->method('getValue')->willReturnCallback(function ($field) {
            $key = $field instanceof BackedEnum ? $field->value : (string)$field;
            return match ($key) {
                ApplicationField::ESNcardNumber->value => null,
                ApplicationField::PaymentLinkID->value => 'plink_failing',
                default => null,
            };
        });
        $application->method('addApprovalStatus')
            ->with(ApprovalStatuses::Paid)
            ->willReturn(true);

        $application->expects($this->exactly(2))
            ->method('setValue')
            ->willReturnSelf();

        $application->expects($this->once())->method('save');

        $transaction = $this->createMock(Transaction::class);
        $this->database->expects($this->once())
            ->method('startTransaction')
            ->willReturn($transaction);

        $this->lock->expects($this->once())
            ->method('acquire')
            ->with('process_application_42')
            ->willReturn(true);

        $this->lock->expects($this->once())
            ->method('release')
            ->with('process_application_42');

        $this->esncardService->expects($this->once())
            ->method('assignESNcardNumber')
            ->with($application, true)
            ->willReturn('11223344');

        $this->stripeService->expects($this->once())
            ->method('disablePaymentLink')
            ->with('plink_failing')
            ->willThrowException(new Exception('Stripe connection timeout'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Application @id processed, but failed to deactivate Stripe Payment Link @linkID: @message',
                [
                    '@id' => '42',
                    '@linkID' => 'plink_failing',
                    '@message' => 'Stripe connection timeout',
                ]
            );

        $this->logger->expects($this->once())
            ->method('notice')
            ->with(
                'Application @id marked as Paid and assigned ESNcard number.',
                ['@id' => '42']
            );

        $this->esncardService->expects($this->once())
            ->method('postAssignment')
            ->with($application, true);

        $action = $this->createAction();
        $result = $action->execute($application);

        $this->assertSame('ESNcard payment was processed successfully', $result);
    }

    public function testAccessAllowedReturnsBoolean(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('mark applications as paid')->willReturn(true);

        $action = $this->createAction();
        $this->assertTrue($action->access(null, $account));
    }

    public function testAccessForbiddenReturnsBoolean(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('mark applications as paid')->willReturn(false);

        $action = $this->createAction();
        $this->assertFalse($action->access(null, $account));
    }

    public function testAccessAllowedReturnsAccessResultObject(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('mark applications as paid')->willReturn(true);

        $action = $this->createAction();
        $access = $action->access(null, $account, true);

        $this->assertInstanceOf(AccessResultInterface::class, $access);
        $this->assertTrue($access->isAllowed());
    }

    public function testAccessForbiddenReturnsAccessResultObject(): void
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('hasPermission')->with('mark applications as paid')->willReturn(false);

        $action = $this->createAction();
        $access = $action->access(null, $account, true);

        $this->assertInstanceOf(AccessResultInterface::class, $access);
        $this->assertFalse($access->isAllowed());
    }
}
