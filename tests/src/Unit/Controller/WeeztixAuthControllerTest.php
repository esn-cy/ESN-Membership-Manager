<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Controller\WeeztixAuthController;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @covers \Drupal\esn_membership_manager\Controller\WeeztixAuthController
 * @group esn_membership_manager
 */
class WeeztixAuthControllerTest extends MembershipManagerTestCase
{
    private MessengerInterface $messenger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->messenger = $this->createMock(MessengerInterface::class);
        $this->container->set('messenger', $this->messenger);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')
            ->willReturnCallback(function ($name, $parameters = [], $options = []) {
                return '/dummy/' . $name;
            });
        $this->container->set('url_generator', $urlGenerator);
    }

    public function testCreate(): void
    {
        $weeztixService = $this->createMock(WeeztixService::class);
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with('esn_membership_manager.weeztix_service')
            ->willReturn($weeztixService);

        $controller = WeeztixAuthController::create($container);
        $this->assertInstanceOf(WeeztixAuthController::class, $controller);
    }

    public function testCallbackNoCode(): void
    {
        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->callback(fn($msg) => $msg->getUntranslatedString() === 'No authorization code received from Weeztix.'));

        $weeztixService = $this->createMock(WeeztixService::class);
        $controller = new WeeztixAuthController($weeztixService);

        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.settings', $response->getTargetUrl());
    }

    public function testCallbackStateMismatch(): void
    {
        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->callback(fn($msg) => $msg->getUntranslatedString() === 'Invalid state parameter. Possible CSRF attempt.'));

        $weeztixService = $this->createMock(WeeztixService::class);
        $controller = new WeeztixAuthController($weeztixService);

        $session = new Session(new MockArraySessionStorage());
        $session->set('weeztix_oauth_state', 'expected_state_abc');

        $request = new Request(['code' => 'auth_code_123', 'state' => 'wrong_state']);
        $request->setSession($session);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.settings', $response->getTargetUrl());
    }

    public function testCallbackSuccess(): void
    {
        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->callback(fn($msg) => $msg->getUntranslatedString() === 'Successfully connected to Weeztix!'));

        $weeztixService = $this->createMock(WeeztixService::class);
        $weeztixService->expects($this->once())
            ->method('authorizeWithCode')
            ->with('valid_code', '/dummy/esn_membership_manager.weeztix_oauth_callback')
            ->willReturn(true);

        $controller = new WeeztixAuthController($weeztixService);

        $session = new Session(new MockArraySessionStorage());
        $session->set('weeztix_oauth_state', 'state_xyz');

        $request = new Request(['code' => 'valid_code', 'state' => 'state_xyz']);
        $request->setSession($session);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertFalse($session->has('weeztix_oauth_state'));
        $this->assertEquals('/dummy/esn_membership_manager.settings', $response->getTargetUrl());
    }

    public function testCallbackAuthorizeFailure(): void
    {
        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->callback(fn($msg) => $msg->getUntranslatedString() === 'Failed to exchange code for access token. Check logs.'));

        $weeztixService = $this->createMock(WeeztixService::class);
        $weeztixService->expects($this->once())
            ->method('authorizeWithCode')
            ->with('valid_code', '/dummy/esn_membership_manager.weeztix_oauth_callback')
            ->willReturn(false);

        $controller = new WeeztixAuthController($weeztixService);

        $session = new Session(new MockArraySessionStorage());
        $session->set('weeztix_oauth_state', 'state_xyz');

        $request = new Request(['code' => 'valid_code', 'state' => 'state_xyz']);
        $request->setSession($session);

        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertFalse($session->has('weeztix_oauth_state'));
        $this->assertEquals('/dummy/esn_membership_manager.settings', $response->getTargetUrl());
    }
}
