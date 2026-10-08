<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\GuestPassApprovalEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\GuestPassApprovalEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class GuestPassApprovalEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_guest_approval', GuestPassApprovalEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'guest_pass_name' => 'Custom Guest Pass',
            ],
        ]);

        $email = new GuestPassApprovalEmail(self::TEST_SECONDARY_FULL_NAME, 'TKN1', 'https://g', 'https://a');
        $email->inject($configFactory);

        $this->assertEquals('Custom Guest Pass Approved', $email->getSubject());
    }

    public function testGetSubjectDefault(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
        ]);

        $email = new GuestPassApprovalEmail(self::TEST_SECONDARY_FULL_NAME, 'TKN1', 'https://g', 'https://a');
        $email->inject($configFactory);

        $this->assertEquals('ESN Guest Pass Approved', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new GuestPassApprovalEmail(self::TEST_SECONDARY_FULL_NAME, 'TKN1', 'https://g', 'https://a');
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('name', $variables);
        $this->assertEquals(self::TEST_SECONDARY_FULL_NAME, $variables['name']);

        $this->assertArrayHasKey('pass_token', $variables);
        $this->assertEquals('TKN1', $variables['pass_token']);

        $this->assertArrayHasKey('google_wallet_link', $variables);
        $this->assertEquals('https://g', $variables['google_wallet_link']);

        $this->assertArrayHasKey('apple_wallet_link', $variables);
        $this->assertEquals('https://a', $variables['apple_wallet_link']);
    }
}
