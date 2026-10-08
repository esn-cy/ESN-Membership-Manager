<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\PassApprovalEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\PassApprovalEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class PassApprovalEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_pass_approval', PassApprovalEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'pass_name' => 'ESN Super Pass',
            ],
        ]);

        $email = new PassApprovalEmail(self::TEST_FULL_NAME, self::TEST_TOKEN);
        $email->inject($configFactory);

        $this->assertEquals('ESN Super Pass Approval', $email->getSubject());
    }

    public function testGetSubjectDefault(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
        ]);

        $email = new PassApprovalEmail(self::TEST_FULL_NAME, self::TEST_TOKEN);
        $email->inject($configFactory);

        $this->assertEquals('ESN Pass Approval', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new PassApprovalEmail(self::TEST_FULL_NAME, self::TEST_TOKEN, 'https://google.wallet', 'https://apple.wallet');
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('name', $variables);
        $this->assertEquals(self::TEST_FULL_NAME, $variables['name']);

        $this->assertArrayHasKey('pass_token', $variables);
        $this->assertEquals(self::TEST_TOKEN, $variables['pass_token']);

        $this->assertArrayHasKey('google_wallet_link', $variables);
        $this->assertEquals('https://google.wallet', $variables['google_wallet_link']);

        $this->assertArrayHasKey('apple_wallet_link', $variables);
        $this->assertEquals('https://apple.wallet', $variables['apple_wallet_link']);
    }

    public function testGetVariablesWithNullLinks(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new PassApprovalEmail(self::TEST_SECONDARY_FULL_NAME, 'TOKEN456');
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertEquals('', $variables['google_wallet_link']);
        $this->assertEquals('', $variables['apple_wallet_link']);
    }
}
