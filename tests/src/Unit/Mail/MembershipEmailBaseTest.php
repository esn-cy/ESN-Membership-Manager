<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Mail\MembershipEmailBase;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 */
class MembershipEmailBaseTest extends MembershipManagerTestCase
{
    public function testGetModuleName(): void
    {
        $this->assertEquals('esn_membership_manager', MembershipEmailBase::getModuleName());
    }

    public function testGetFromEmail(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'email_address' => 'test@example.com',
            ],
        ]);

        $email = new class() extends MembershipEmailBase {
            public static function getKey(): string
            {
                return 'test';
            }

            public function getSubject(): string
            {
                return 'test';
            }
        };
        $email->inject($configFactory);

        $this->assertEquals('test@example.com', $email->getFromEmail());
    }

    public function testGetFromName(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'email_name' => 'Test Name',
            ],
        ]);

        $email = new class() extends MembershipEmailBase {
            public static function getKey(): string
            {
                return 'test';
            }

            public function getSubject(): string
            {
                return 'test';
            }
        };
        $email->inject($configFactory);

        $this->assertEquals('Test Name', $email->getFromName());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'pass_name' => 'Super Pass',
                'guest_pass_name' => 'Super Guest Pass',
                'email_footer' => 'Custom Footer',
            ],
            'omnia.settings' => [
                'organisation_name' => 'ESN',
                'email_footer' => 'Base Footer'
            ]
        ]);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects($this->once())
            ->method('generateFromRoute')
            ->with('esn_membership_manager.dashboard', [], ['absolute' => TRUE])
            ->willReturn('https://example.com/dashboard');

        $this->container?->set('url_generator', $urlGenerator);

        $email = new class() extends MembershipEmailBase {
            public static function getKey(): string
            {
                return 'test';
            }

            public function getSubject(): string
            {
                return 'test';
            }
        };
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertEquals('Student', $variables['name']);
        $this->assertEquals('Super Pass', $variables['scheme_name']);
        $this->assertEquals('Super Guest Pass', $variables['guest_scheme_name']);
        $this->assertInstanceOf(Url::class, $variables['dashboard_url']);
        $this->assertEquals('https://example.com/dashboard', $variables['dashboard_url']->toString());
        $this->assertEquals('Custom Footer', $variables['footer']);
    }
}
