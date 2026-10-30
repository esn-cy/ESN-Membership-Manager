<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Page;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Page\AboutPage;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @covers \Drupal\esn_membership_manager\Page\AboutPage
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class AboutPageTest extends MembershipManagerTestCase
{
    public function testCreate(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $module = $this->createMock(Extension::class);
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(fn($id) => match ($id) {
            'config.factory' => $configFactory,
            'module_handler' => $moduleHandler,
            default => null,
        });

        $page = AboutPage::create($container);
        $this->assertInstanceOf(AboutPage::class, $page);
    }

    public function testAboutPageWithLogoFileExists(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'pass_name' => 'Custom Pass',
                'guest_pass_name' => 'Custom Guest Pass',
            ],
            OmniaSettings::CONFIG_NAME => [
                'organisation_name' => 'ESN Test Country',
            ],
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturnCallback(function ($route) {
            return match ($route) {
                'esn_membership_manager.apply' => '/memberships/apply',
                'esn_membership_manager.dashboard' => '/memberships/dashboard',
                'esn_membership_manager.terms_of_service' => '/memberships/terms',
                'esn_membership_manager.privacy_policy' => '/memberships/privacy',
                default => '/unknown',
            };
        });
        $this->container->set('url_generator', $urlGenerator);

        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn(realpath(__DIR__ . '/../../../../'));
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $page = new AboutPage($configFactory, $moduleHandler);
        $renderArray = $page->aboutPage();

        $this->assertEquals('markup', $renderArray['#type']);
        $this->assertStringContainsString('About Your Mobility Membership', (string)$renderArray['#markup']);
        $this->assertStringContainsString('Custom Pass', (string)$renderArray['#markup']);
        $this->assertStringContainsString('Custom Guest Pass', (string)$renderArray['#markup']);
        $this->assertStringContainsString('ESN Test Country', (string)$renderArray['#markup']);
        $this->assertStringContainsString('/memberships/apply', (string)$renderArray['#markup']);
        $this->assertStringContainsString('/memberships/dashboard', (string)$renderArray['#markup']);
        $this->assertStringContainsString('<svg', (string)$renderArray['#markup']);
        $this->assertContains('esn_membership_manager/membership_dashboard', $renderArray['#attached']['library']);
        $this->assertContains('esn_membership_manager/application_form', $renderArray['#attached']['library']);
    }

    public function testAboutPageWithLogoFileMissing(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturn('/path');
        $this->container->set('url_generator', $urlGenerator);

        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/invalid/nonexistent/path');
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($module);

        $page = new AboutPage($configFactory, $moduleHandler);
        $renderArray = $page->aboutPage();

        $this->assertEquals('markup', $renderArray['#type']);
        $this->assertStringContainsString('About Your Mobility Membership', (string)$renderArray['#markup']);
    }
}
