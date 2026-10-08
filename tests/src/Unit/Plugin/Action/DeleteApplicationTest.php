<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Plugin\Action\DeleteApplication;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for DeleteApplication action.
 *
 * @covers \Drupal\esn_membership_manager\Plugin\Action\DeleteApplication
 * @group esn_membership_manager
 */
class DeleteApplicationTest extends MembershipManagerTestCase
{
    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->with('logger.factory')
            ->willReturn($this->getLoggerFactoryMock());

        $action = DeleteApplication::create($container, [], 'esn_membership_manager_delete', []);
        $this->assertInstanceOf(DeleteApplication::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->never())->method('error');

        $action = new DeleteApplication([], 'delete_id', [], $this->getLoggerFactoryMock($logger));

        $action->execute();
        $this->assertTrue(true);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccess(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->expects($this->once())->method('delete');

        $action = new DeleteApplication([], 'delete_id', [], $this->getLoggerFactoryMock());
        $action->execute($app);
    }

    public function testExecuteHandlesExceptionFromDelete(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('123');
        $app->method('delete')->willThrowException(new Exception('Database error'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Unable to delete application @id: @message', [
                '@id' => '123',
                '@message' => 'Database error',
            ]);

        $action = new DeleteApplication([], 'delete_id', [], $this->getLoggerFactoryMock($logger));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to complete deletion process');

        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new DeleteApplication([], 'delete_id', [], $this->getLoggerFactoryMock());

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('delete applications')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('delete applications')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $resultObject = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $resultObject);
        $this->assertTrue($resultObject->isAllowed());
    }
}
