<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Mail\BlacklistEmail;
use Drupal\esn_membership_manager\Plugin\Action\BlacklistApplication;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for BlacklistApplication action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\BlacklistApplication
 * @uses         \Drupal\esn_membership_manager\Mail\BlacklistEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class BlacklistApplicationTest extends MembershipManagerTestCase
{
    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['omnia.email_service', 1, $this->createMock(EmailService::class)],
            ['logger.factory', 1, $this->getLoggerFactoryMock()],
            ['esn_membership_manager.stripe_service', 1, $this->createMock(StripeService::class)],
        ]);

        $action = BlacklistApplication::create($container, [], 'esn_membership_manager_blacklist', []);
        $this->assertInstanceOf(BlacklistApplication::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new BlacklistApplication([], 'blacklist_id', [], $emailService, $this->getLoggerFactoryMock(), $this->createMock(StripeService::class));
        $action->execute();
        $this->assertTrue(true);
    }

    public function testExecuteThrowsWhenStatusCannotBeApplied(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('201');
        $app->method('addApprovalStatus')
            ->with(ApprovalStatuses::Blacklisted)
            ->willReturn('This status cannot be applied to a pending application.');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id cannot be marked as blacklisted. @issues.', [
                '@id' => '201',
                '@issues' => 'This status cannot be applied to a pending application.',
            ]);

        $action = new BlacklistApplication([], 'blacklist_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock($logger), $this->createMock(StripeService::class));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This status cannot be applied.');

        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithPaymentLinkDisabled(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('202');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Blacklisted)->willReturn(true);
        $app->expects($this->once())->method('save');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::PaymentLinkID => 'plink_123',
                ApplicationField::Name => 'Mario',
                ApplicationField::Email => 'mario@example.com',
                default => null,
            };
        });

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('disablePaymentLink')
            ->with('plink_123');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Blacklisted application @id', ['@id' => '202']);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'mario@example.com',
                $this->callback(function (BlacklistEmail $email) {
                    $this->assertSame('Mario', $email->getVariables()['name']);
                    return true;
                })
            );

        $action = new BlacklistApplication([], 'blacklist_id', [], $emailService, $this->getLoggerFactoryMock($logger), $stripeService);
        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteIgnoresStripeExceptionWhenDisablingPaymentLink(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('203');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Blacklisted)->willReturn(true);
        $app->expects($this->once())->method('save');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::PaymentLinkID => 'plink_err',
                ApplicationField::Name => 'Luigi',
                ApplicationField::Email => 'luigi@example.com',
                default => null,
            };
        });

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('disablePaymentLink')
            ->willThrowException(new Exception('Stripe connection lost'));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())->method('send');

        $action = new BlacklistApplication([], 'blacklist_id', [], $emailService, $this->getLoggerFactoryMock(), $stripeService);
        $action->execute($app);
    }

    public function testExecuteHandlesSaveException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('204');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Blacklisted)->willReturn(true);
        $app->method('getValue')->with(ApplicationField::PaymentLinkID)->willReturn(null);
        $app->method('save')->willThrowException(new Exception('Database error on save'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unable to blacklist application @id: @message', [
                '@id' => '204',
                '@message' => 'Database error on save',
            ]);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new BlacklistApplication([], 'blacklist_id', [], $emailService, $this->getLoggerFactoryMock($logger), $this->createMock(StripeService::class));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to complete blacklisting process');

        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new BlacklistApplication([], 'blacklist_id', [], $this->createMock(EmailService::class), $this->getLoggerFactoryMock(), $this->createMock(StripeService::class));

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('blacklist applications')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('blacklist applications')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
