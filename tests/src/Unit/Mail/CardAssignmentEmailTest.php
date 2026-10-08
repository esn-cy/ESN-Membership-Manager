<?php

namespace Drupal\Tests\esn_membership_manager\Unit\Mail;

use Drupal\esn_membership_manager\Mail\CardAssignmentEmail;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Mail\CardAssignmentEmail
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses   \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 */
class CardAssignmentEmailTest extends MembershipManagerTestCase
{
    public function testGetKey(): void
    {
        $this->assertEquals('emm_card_assignment', CardAssignmentEmail::getKey());
    }

    public function testGetSubject(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'omnia.settings' => [],
        ]);

        $email = new CardAssignmentEmail(self::TEST_SECONDARY_FULL_NAME, '123456', 'https://g', 'https://a');
        $email->inject($configFactory);

        $this->assertEquals('Your ESNcard Details', $email->getSubject());
    }

    public function testGetVariables(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            'esn_membership_manager.settings' => [],
            'omnia.settings' => []
        ]);

        $email = new CardAssignmentEmail(self::TEST_SECONDARY_FULL_NAME, '123456', 'https://g', 'https://a');
        $email->inject($configFactory);

        $variables = $email->getVariables();

        $this->assertArrayHasKey('name', $variables);
        $this->assertEquals(self::TEST_SECONDARY_FULL_NAME, $variables['name']);

        $this->assertArrayHasKey('esncard_number', $variables);
        $this->assertEquals('123456', $variables['esncard_number']);

        $this->assertArrayHasKey('google_wallet_link', $variables);
        $this->assertEquals('https://g', $variables['google_wallet_link']);

        $this->assertArrayHasKey('apple_wallet_link', $variables);
        $this->assertEquals('https://a', $variables['apple_wallet_link']);
    }
}
