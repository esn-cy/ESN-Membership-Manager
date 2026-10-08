<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Controller\FileAccessController;
use Drupal\esn_membership_manager\StreamWrapper\MembershipStreamWrapper;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\FileAccessController
 * @uses         \Drupal\esn_membership_manager\StreamWrapper\MembershipStreamWrapper
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class FileAccessControllerTest extends MembershipManagerTestCase
{
    public function testCreate(): void
    {
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('logger.factory')
            ->willReturn($loggerFactory);

        $controller = FileAccessController::create($container);
        $this->assertInstanceOf(FileAccessController::class, $controller);
    }

    public function testSchemeName(): void
    {
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new FileAccessController($loggerFactory);
        $this->assertEquals('membership', $controller->schemeName());
    }

    public function testStreamWrapper(): void
    {
        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $controller = new FileAccessController($loggerFactory);
        $this->assertInstanceOf(MembershipStreamWrapper::class, $controller->streamWrapper());
    }

    public function testDownloadMembershipFileNotFoundThrowsNotFoundException(): void
    {
        if (!defined('DRUPAL_ROOT')) {
            define('DRUPAL_ROOT', dirname(__DIR__, 4));
        }

        if (!in_array('membership', stream_get_wrappers(), true)) {
            stream_wrapper_register('membership', MembershipStreamWrapper::class);
            $registered = true;
        } else {
            $registered = false;
        }

        try {
            $logger = $this->createMock(LoggerChannelInterface::class);
            $logger->expects($this->once())
                ->method('warning');
            $loggerFactory = $this->getLoggerFactoryMock($logger);

            $controller = new FileAccessController($loggerFactory);

            $this->expectException(NotFoundHttpException::class);
            $controller->downloadMembershipFile('42', 'nonexistent.pdf');
        } finally {
            if ($registered && in_array('membership', stream_get_wrappers(), true)) {
                stream_wrapper_unregister('membership');
            }
        }
    }
}
