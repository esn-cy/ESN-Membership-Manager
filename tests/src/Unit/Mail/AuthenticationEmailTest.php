<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\AuthenticationEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\AuthenticationEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class AuthenticationEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_authentication', AuthenticationEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'omnia.settings' => [
                'organisation_name' => 'ESN Cyprus',
            ],
        ]);

        $email = new AuthenticationEmail('login', '123456');
        $email->inject($configFactory);

        $this->assertEquals('ESN Cyprus Authentication Code', $email->getSubject());
    }

    public function testGetSubjectDefault(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'omnia.settings' => [],
        ]);

        $email = new AuthenticationEmail('login', '123456');
        $email->inject($configFactory);

        $this->assertEquals('ESN Authentication Code', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new AuthenticationEmail('login', '123456');
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('authentication_type', $variables);
        $this->assertEquals('login', $variables['authentication_type']);

        $this->assertArrayHasKey('authentication_code', $variables);
        $this->assertEquals('123456', $variables['authentication_code']);
    }
}
