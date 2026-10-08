<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\PassConfirmationEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\PassConfirmationEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class PassConfirmationEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_pass_confirmation', PassConfirmationEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'pass_name' => 'Custom Pass',
            ],
        ]);

        $email = new PassConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $this->assertEquals('Custom Pass Application Received', $email->getSubject());
    }

    public function testGetSubjectDefault(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
        ]);

        $email = new PassConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $this->assertEquals('ESN Pass Application Received', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new PassConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('name', $variables);
        $this->assertEquals(self::TEST_SECONDARY_FULL_NAME, $variables['name']);
    }
}
