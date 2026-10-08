<?php

namespace Drupal\Tests\esn_membership_manager\Unit\StreamWrapper;

use Drupal;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\StreamWrapper\MembershipStreamWrapper;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\StreamWrapper\MembershipStreamWrapper
 * @group esn_membership_manager
 */
class MembershipStreamWrapperTest extends MembershipManagerTestCase
{
    public function testModuleMachineName(): void
    {
        $wrapper = new MembershipStreamWrapper();
        $this->assertEquals('esn_membership_manager', $wrapper->moduleMachineName());
    }

    public function testModuleFormatedName(): void
    {
        $wrapper = new MembershipStreamWrapper();
        $this->assertEquals('ESN Membership Manager', $wrapper->moduleFormatedName());
    }

    public function testIsPrivate(): void
    {
        $wrapper = new MembershipStreamWrapper();
        $this->assertTrue($wrapper->isPrivate());
    }

    public function testGetExternalUrlWithFilename(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generateFromRoute')
            ->with('esn_membership_manager.file_download', [
                'applicationID' => '123',
                'filename' => 'test.pdf'
            ], ['absolute' => TRUE])
            ->willReturn('https://example.com/download/123/test.pdf');

        $container = new ContainerBuilder();
        $container->set('url_generator', $urlGenerator);
        Drupal::setContainer($container);

        $wrapper = new MembershipStreamWrapper();
        $wrapper->setUri('membership://123/test.pdf');

        $this->assertEquals('https://example.com/download/123/test.pdf', $wrapper->getExternalUrl());
    }

    public function testGetExternalUrlWithoutFilename(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generateFromRoute')
            ->with('<front>', [], ['absolute' => TRUE])
            ->willReturn('https://example.com/');

        $container = new ContainerBuilder();
        $container->set('url_generator', $urlGenerator);
        Drupal::setContainer($container);

        $wrapper = new MembershipStreamWrapper();
        $wrapper->setUri('membership://123');

        $this->assertEquals('https://example.com/', $wrapper->getExternalUrl());
    }
}
