<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\esn_membership_manager\Controller\AppleAuthController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\ClientInterface;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * @covers \Drupal\esn_membership_manager\Controller\AppleAuthController
 * @uses   \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 */
class AppleAuthControllerTest extends MembershipManagerTestCase
{
    private ApplicationStorage $applicationStorage;
    private Connection $database;
    private ClientInterface $httpClient;
    private MessengerInterface $messenger;
    private Session $session;
    private Request $request;
    private AppleAuthController $controller;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($this->applicationStorage);

        $this->database = $this->createMock(Connection::class);

        $config = $this->createMock(ImmutableConfig::class);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('esn_membership_manager.settings')->willReturn($config);

        $settings = new Settings(['hash_salt' => 'test_salt']);
        $cache = $this->createMock(CacheBackendInterface::class);
        $this->httpClient = $this->createMock(ClientInterface::class);
        $logger = $this->createMock(LoggerChannelInterface::class);

        $this->messenger = $this->createMock(MessengerInterface::class);

        $this->session = new Session(new MockArraySessionStorage());
        $this->request = new Request();
        $this->request->setSession($this->session);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturnCallback(function ($route) {
            return match ($route) {
                'esn_membership_manager.dashboard' => '/memberships/dashboard',
                'esn_membership_manager.apply' => '/memberships/apply',
                'esn_membership_manager.apple_auth' => 'https://example.com/memberships/auth/apple',
                'esn_membership_manager.about' => '/memberships/about',
                default => '/unknown',
            };
        });

        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('database', $this->database);
        $this->container->set('config.factory', $configFactory);
        $this->container->set('settings', $settings);
        $this->container->set('cache.default', $cache);
        $this->container->set('http_client', $this->httpClient);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));
        $this->container->set('messenger', $this->messenger);
        $this->container->set('url_generator', $urlGenerator);

        $this->controller = new AppleAuthController(
            $entityTypeManager,
            $this->database,
            $configFactory,
            $settings,
            $cache,
            $this->httpClient,
            $this->getLoggerFactoryMock($logger)
        );
        $this->controller->setMessenger($this->messenger);
        $this->controller->setStringTranslation($this->container->get('string_translation'));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $controller = AppleAuthController::create($this->container);
        $this->assertInstanceOf(AppleAuthController::class, $controller);
    }

    /**
     * @throws ReflectionException
     * @throws PluginNotFoundException
     * @throws InvalidPluginDefinitionException
     */
    public function testGetClientID(): void
    {
        $methodId = new ReflectionMethod($this->controller, 'getClientID');
        $this->assertEquals('', $methodId->invoke($this->controller));

        $config = $this->createMock(ImmutableConfig::class);
        $config->method('get')->willReturnMap([
            ['apple_auth_client_id', 'test_apple_client_id_123'],
        ]);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('get')->with('esn_membership_manager.settings')->willReturn($config);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($this->applicationStorage);

        $controller = new AppleAuthController(
            $entityTypeManager,
            $this->database,
            $configFactory,
            new Settings(['hash_salt' => 'test_salt']),
            $this->createMock(CacheBackendInterface::class),
            $this->httpClient,
            $this->getLoggerFactoryMock()
        );

        $this->assertEquals('test_apple_client_id_123', $methodId->invoke($controller));
    }

    /**
     * @throws ReflectionException
     */
    public function testGetRedirectURI(): void
    {
        $method = new ReflectionMethod($this->controller, 'getRedirectURI');
        $result = $method->invoke($this->controller, $this->request);
        $this->assertEquals('https://example.com/memberships/auth/apple', $result);
    }

    /**
     * @throws ReflectionException
     */
    public function testLoginSuccessWhenApplicationExists(): void
    {
        $this->applicationStorage->method('countByEmail')->with(self::TEST_EMAIL)->willReturn(1);

        $payload = ['email' => self::TEST_EMAIL];
        $stateData = ['type' => 'login'];

        $method = new ReflectionMethod($this->controller, 'onAppleAuthSuccess');
        /** @var RedirectResponse $response */
        $response = $method->invoke($this->controller, self::TEST_EMAIL, $payload, $stateData, $this->request);

        $this->assertEquals('/memberships/dashboard', $response->getTargetUrl());
        $this->assertEquals(self::TEST_EMAIL, $this->session->get('login_verified_email'));
        $this->assertEquals(['auth_success' => true, 'email' => self::TEST_EMAIL], $this->session->get('login_email_authentication_data'));
    }

    /**
     * @throws ReflectionException
     */
    public function testLoginFailsWhenApplicationDoesNotExist(): void
    {
        $this->applicationStorage->method('countByEmail')->with(self::TEST_EMAIL)->willReturn(0);
        $this->messenger->expects($this->once())->method('addError');

        $payload = ['email' => self::TEST_EMAIL];
        $stateData = ['type' => 'login'];

        $method = new ReflectionMethod($this->controller, 'onAppleAuthSuccess');
        /** @var RedirectResponse $response */
        $response = $method->invoke($this->controller, self::TEST_EMAIL, $payload, $stateData, $this->request);

        $this->assertEquals('/memberships/apply', $response->getTargetUrl());
        $this->assertNull($this->session->get('login_verified_email'));
    }

    /**
     * @throws ReflectionException
     */
    public function testRegisterFailsWhenApplicationAlreadyExists(): void
    {
        $this->applicationStorage->method('countByEmail')->with(self::TEST_EMAIL)->willReturn(1);
        $this->messenger->expects($this->once())->method('addError');

        $payload = ['email' => self::TEST_EMAIL];
        $stateData = ['type' => 'register'];

        $method = new ReflectionMethod($this->controller, 'onAppleAuthSuccess');
        /** @var RedirectResponse $response */
        $response = $method->invoke($this->controller, self::TEST_EMAIL, $payload, $stateData, $this->request);

        $this->assertEquals('/memberships/dashboard', $response->getTargetUrl());
    }

    /**
     * @throws ReflectionException
     */
    public function testRegisterSuccessCreatesInProgressApplicationAndPrefillsData(): void
    {
        $this->applicationStorage->method('countByEmail')->with(self::TEST_EMAIL)->willReturn(0);

        $mergeQuery = $this->createMock(Merge::class);
        $mergeQuery->method('key')->willReturnSelf();
        $mergeQuery->method('fields')->willReturnSelf();
        $mergeQuery->expects($this->once())->method('execute');

        $this->database->method('merge')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($mergeQuery);

        $this->messenger->expects($this->once())->method('addStatus');

        $payload = [
            'email' => self::TEST_EMAIL,
            'given_name' => 'John',
            'family_name' => 'Doe',
        ];
        $stateData = ['type' => 'register'];

        $method = new ReflectionMethod($this->controller, 'onAppleAuthSuccess');
        /** @var RedirectResponse $response */
        $response = $method->invoke($this->controller, self::TEST_EMAIL, $payload, $stateData, $this->request);

        $this->assertEquals('/memberships/apply', $response->getTargetUrl());
        $this->assertEquals(self::TEST_EMAIL, $this->session->get('register_verified_email'));
        $this->assertEquals(['auth_success' => true, 'email' => self::TEST_EMAIL], $this->session->get('register_email_authentication_data'));

        $savedData = $this->session->get('application_form_saved_data');
        $this->assertEquals(self::TEST_EMAIL, $savedData['email']);
    }

    /**
     * @throws ReflectionException
     */
    public function testOnAppleAuthSuccessWithUnknownTypeRedirectsToAbout(): void
    {
        $this->messenger->expects($this->once())->method('addError');

        $payload = ['email' => self::TEST_EMAIL];
        $stateData = ['type' => 'unknown'];

        $method = new ReflectionMethod($this->controller, 'onAppleAuthSuccess');
        /** @var RedirectResponse $response */
        $response = $method->invoke($this->controller, self::TEST_EMAIL, $payload, $stateData, $this->request);

        $this->assertEquals('/memberships/about', $response->getTargetUrl());
    }

    /**
     * @throws ReflectionException
     */
    public function testAuthFailureRedirectsBasedOnState(): void
    {
        $this->messenger->expects($this->exactly(3))->method('addError');

        $method = new ReflectionMethod($this->controller, 'onAppleAuthFailure');

        /** @var RedirectResponse $loginFailResponse */
        $loginFailResponse = $method->invoke($this->controller, 'Error message', ['type' => 'login'], $this->request);
        $this->assertEquals('/memberships/dashboard', $loginFailResponse->getTargetUrl());

        /** @var RedirectResponse $regFailResponse */
        $regFailResponse = $method->invoke($this->controller, 'Error message', ['type' => 'register'], $this->request);
        $this->assertEquals('/memberships/apply', $regFailResponse->getTargetUrl());

        /** @var RedirectResponse $defaultFailResponse */
        $defaultFailResponse = $method->invoke($this->controller, 'Error message', null, $this->request);
        $this->assertEquals('/memberships/dashboard', $defaultFailResponse->getTargetUrl());
    }
}
