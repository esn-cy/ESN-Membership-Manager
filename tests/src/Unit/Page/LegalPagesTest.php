<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Page;

use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Page\LegalPages;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @covers       \Drupal\esn_membership_manager\Page\LegalPages
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class LegalPagesTest extends MembershipManagerTestCase
{
    public function testCreate(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('config.factory')
            ->willReturn($configFactory);

        $page = LegalPages::create($container);
        $this->assertInstanceOf(LegalPages::class, $page);
    }

    public function testTosPage(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
            OmniaSettings::CONFIG_NAME => [
                'organisation_name' => 'ESN Cyprus',
            ],
        ]);

        $page = new LegalPages($configFactory);
        $renderArray = $page->tosPage();

        $this->assertEquals('markup', $renderArray['#type']);
        $this->assertStringContainsString('<h1>Terms of Service</h1>', $renderArray['#markup']);
        $this->assertStringContainsString('ESN Cyprus', $renderArray['#markup']);
    }

    public function testPrivacyPage(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'pass_name' => 'ESN Pass Cyprus',
            ],
            OmniaSettings::CONFIG_NAME => [
                'organisation_name' => 'ESN Cyprus',
            ],
        ]);

        $page = new LegalPages($configFactory);
        $renderArray = $page->privacyPage();

        $this->assertEquals('markup', $renderArray['#type']);
        $this->assertStringContainsString('<h1>Privacy Policy</h1>', $renderArray['#markup']);
        $this->assertStringContainsString('ESN Cyprus', $renderArray['#markup']);
        $this->assertStringContainsString('ESN Pass Cyprus', $renderArray['#markup']);
    }
}
