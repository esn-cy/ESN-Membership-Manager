<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class OnlineStatusController extends ControllerBase
{
    public function checkOnlineStatus(): JsonResponse
    {
        return new JsonResponse(['status' => 'ESM Membership Manager is online'], 200);
    }
}