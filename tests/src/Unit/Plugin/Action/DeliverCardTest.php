<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Plugin\Action\DeliverCard;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for DeliverCard action.
 *
 * @covers \Drupal\esn_membership_manager\Plugin\Action\DeliverCard
 * @group esn_membership_manager
 */
class DeliverCardTest extends MembershipManagerTestCase
{
    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('logger.factory')
            ->willReturn($this->getLoggerFactoryMock());

        $action = DeliverCard::create($container, [], 'esn_membership_manager_deliver', []);
        $this->assertInstanceOf(DeliverCard::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->never())->method('warning');

        $action = new DeliverCard([], 'deliver_id', [], $this->getLoggerFactoryMock($logger));
        $action->execute();
        $this->assertTrue(true);
    }

    public function testExecuteThrowsWhenStatusCannotBeApplied(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('456');
        $app->method('addApprovalStatus')
            ->with(ApprovalStatuses::Delivered)
            ->willReturn('This status has been applied out of order.');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id cannot be marked as delivered. @issues.', [
                '@id' => '456',
                '@issues' => 'This status has been applied out of order.',
            ]);

        $action = new DeliverCard([], 'deliver_id', [], $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This status cannot be applied.');

        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccess(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('789');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Delivered)->willReturn(true);
        $app->expects($this->once())->method('save');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Delivered application @id', ['@id' => '789']);

        $action = new DeliverCard([], 'deliver_id', [], $this->getLoggerFactoryMock($logger));
        $action->execute($app);
    }

    public function testExecuteHandlesSaveException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('789');
        $app->method('addApprovalStatus')->with(ApprovalStatuses::Delivered)->willReturn(true);
        $app->method('save')->willThrowException(new Exception('Write failure'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unable to mark card as delivered @id: @message', [
                '@id' => '789',
                '@message' => 'Write failure',
            ]);

        $action = new DeliverCard([], 'deliver_id', [], $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to complete delivery process');

        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new DeliverCard([], 'deliver_id', [], $this->getLoggerFactoryMock());

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('deliver cards')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('deliver cards')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
