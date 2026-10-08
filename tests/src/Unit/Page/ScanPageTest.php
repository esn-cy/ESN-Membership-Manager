<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Page;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\esn_membership_manager\Page\ScanPage;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @covers \Drupal\esn_membership_manager\Page\ScanPage
 * @group esn_membership_manager
 */
class ScanPageTest extends MembershipManagerTestCase
{
    public function testCreate(): void
    {
        $extension = $this->createMock(Extension::class);
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->expects($this->once())
            ->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($extension);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('module_handler')
            ->willReturn($moduleHandler);

        $page = ScanPage::create($container);
        $this->assertInstanceOf(ScanPage::class, $page);
    }

    public function testScanPageFileExists(): void
    {
        $projectRoot = dirname(__DIR__, 4);
        $extension = $this->createMock(Extension::class);
        $extension->method('getPath')->willReturn($projectRoot);

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($extension);

        $page = new ScanPage($moduleHandler);
        $renderArray = $page->scanPage();

        $this->assertEquals('processed_text', $renderArray['#type']);
        $this->assertEquals('full_html', $renderArray['#format']);
        $this->assertStringContainsString('html', $renderArray['#text']);
        $this->assertStringNotContainsString('Error: Could not load the content file.', $renderArray['#text']);
    }

    public function testScanPageFileNotFound(): void
    {
        $extension = $this->createMock(Extension::class);
        $extension->method('getPath')->willReturn('/invalid/nonexistent/path');

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($extension);

        $page = new ScanPage($moduleHandler);
        $renderArray = $page->scanPage();

        $this->assertEquals('processed_text', $renderArray['#type']);
        $this->assertEquals('full_html', $renderArray['#format']);
        $this->assertEquals('<p>Error: Could not load the content file.</p>', $renderArray['#text']);
    }
}
