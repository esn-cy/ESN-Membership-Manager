<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\BothConfirmationEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\BothConfirmationEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class BothConfirmationEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_both_confirmation', BothConfirmationEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [
                'pass_name' => 'Custom Pass',
            ],
        ]);

        $email = new BothConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $this->assertEquals('Custom Pass and ESNcard Application Received', $email->getSubject());
    }

    public function testGetSubjectDefault(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
        ]);

        $email = new BothConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $this->assertEquals('ESN Pass and ESNcard Application Received', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new BothConfirmationEmail(self::TEST_SECONDARY_FULL_NAME);
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('name', $variables);
        $this->assertEquals(self::TEST_SECONDARY_FULL_NAME, $variables['name']);
    }
}
