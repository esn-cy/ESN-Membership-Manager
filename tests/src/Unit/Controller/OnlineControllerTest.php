<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\esn_membership_manager\Controller\OnlineController;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Controller\OnlineController
 * @group esn_membership_manager
 */
class OnlineControllerTest extends MembershipManagerTestCase
{
    public function testCheckOnlineStatus(): void
    {
        $controller = new OnlineController();
        $response = $controller->checkOnlineStatus();

        $this->assertEquals(200, $response->getStatusCode());
        $data = json_decode((string)$response->getContent(), true);
        $this->assertEquals(['status' => 'ESM Membership Manager is online'], $data);
    }
}
