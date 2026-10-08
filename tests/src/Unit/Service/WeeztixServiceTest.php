<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for WeeztixService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\WeeztixService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class WeeztixServiceTest extends MembershipManagerTestCase
{
    public function testGetAuthorizationUrlReturnsNullWhenClientIdNotConfigured(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => null,
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->getAuthorizationUrl('https://example.com/callback', 'state_123');

        $this->assertNull($result);
    }

    public function testGetAuthorizationUrlReturnsValidUrl(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => 'client_abc',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $url = $service->getAuthorizationUrl('https://example.com/callback', 'state_token_xyz');

        $this->assertNotNull($url);
        $this->assertStringStartsWith('https://login.weeztix.com/login?', $url);
        $this->assertStringContainsString('client_id=client_abc', $url);
        $this->assertStringContainsString('redirect_uri=' . urlencode('https://example.com/callback'), $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('state=state_token_xyz', $url);
    }

    public function testAddCouponFailsWithInvalidType(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Type parameter is invalid.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('invalid_type', 'COUPON123');

        $this->assertFalse($result);
    }

    public function testAddCouponFailsWhenListIdMissing(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_pass_coupon_list_id' => null,
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Weeztix List ID configuration is missing. Please check module settings.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('pass', 'COUPON123');

        $this->assertFalse($result);
    }

    public function testAddCouponFailsWhenAccessTokenCannotBeFetched(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_card_coupon_list_id' => 'list_card_1',
                'weeztix_client_id' => 'cid',
                'weeztix_client_secret' => 'csec',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnMap([
            ['esn_membership_manager.weeztix_access_token', null, null],
            ['esn_membership_manager.weeztix_token_expires', null, null],
            ['esn_membership_manager.weeztix_refresh_token', null, null],
        ]);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000);

        // refresh will fail
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn(new Response(400, [], json_encode(['error' => 'invalid_grant'])));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'COUPON123');

        $this->assertFalse($result);
    }

    public function testAddCouponSuccessForPass(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_pass_coupon_list_id' => 'list_pass_100',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnMap([
            ['esn_membership_manager.weeztix_access_token', null, 'valid_access_token'],
            ['esn_membership_manager.weeztix_token_expires', null, 5000],
        ]);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000); // 5000 > 1000 + 300

        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.weeztix.com/coupon/list_pass_100/codes',
                $this->callback(function ($options) {
                    $this->assertEquals('Bearer valid_access_token', $options['headers']['Authorization']);
                    $this->assertEquals(['codes' => [['code' => 'FREEPASS123']]], $options['json']);
                    return true;
                })
            )
            ->willReturn(new Response(200));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('info')
            ->with('Successfully added coupon @code to Weeztix.', ['@code' => 'FREEPASS123']);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('pass', 'FREEPASS123');

        $this->assertTrue($result);
    }

    public function testAddCouponSuccessWithAdditionalData(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_card_coupon_list_id' => 'list_card_200',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnMap([
            ['esn_membership_manager.weeztix_access_token', null, 'valid_token'],
            ['esn_membership_manager.weeztix_token_expires', null, 5000],
        ]);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000);

        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'PUT',
                'https://api.weeztix.com/coupon/list_card_200/codes',
                $this->callback(function ($options) {
                    $expectedCode = ['code' => 'CARD999', 'applies_to_count' => 1, 'usage_count' => 5];
                    $this->assertEquals(['codes' => [$expectedCode]], $options['json']);
                    return true;
                })
            )
            ->willReturn(new Response(201));

        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'CARD999', ['applies_to_count' => 1, 'usage_count' => 5]);

        $this->assertTrue($result);
    }

    public function testAddCouponFailsOnNon2xxResponse(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_card_coupon_list_id' => 'list_card_200',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnMap([
            ['esn_membership_manager.weeztix_access_token', null, 'valid_token'],
            ['esn_membership_manager.weeztix_token_expires', null, 5000],
        ]);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000);

        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn(new Response(400));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Weeztix API returned unexpected status: @status', ['@status' => 400]);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'CARD999');

        $this->assertFalse($result);
    }

    public function testAddCouponFailsOnGuzzleException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_card_coupon_list_id' => 'list_card_200',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $state->method('get')->willReturnMap([
            ['esn_membership_manager.weeztix_access_token', null, 'valid_token'],
            ['esn_membership_manager.weeztix_token_expires', null, 5000],
        ]);

        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Network drop', new Psr7Request('PUT', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('HTTP Request failed: @message', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'CARD999');

        $this->assertFalse($result);
    }

    public function testAuthorizeWithCodeFailsWhenCredentialsMissing(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => null,
                'weeztix_client_secret' => null,
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Weeztix Authentication configuration is missing. Please check module settings.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->authorizeWithCode('code_123', 'https://example.com/callback');

        $this->assertFalse($result);
    }

    public function testAuthorizeWithCodeSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => 'cid_test',
                'weeztix_client_secret' => 'csec_test',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(1000);

        $tokenResponse = [
            'access_token' => 'new_access_tok',
            'expires_in' => 7200,
            'refresh_token' => 'new_refresh_tok',
        ];

        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://auth.weeztix.com/tokens',
                [
                    'form_params' => [
                        'grant_type' => 'authorization_code',
                        'client_id' => 'cid_test',
                        'client_secret' => 'csec_test',
                        'redirect_uri' => 'https://example.com/callback',
                        'code' => 'auth_code_xyz',
                    ],
                ]
            )
            ->willReturn(new Response(200, [], json_encode($tokenResponse)));

        $matcher = $this->exactly(3);
        $state->expects($matcher)
            ->method('set')
            ->willReturnCallback(function ($key, $value) use ($matcher) {
                match ($matcher->getInvocationCount()) {
                    1 => [$this->assertSame('esn_membership_manager.weeztix_access_token', $key), $this->assertSame('new_access_tok', $value)],
                    2 => [$this->assertSame('esn_membership_manager.weeztix_token_expires', $key), $this->assertSame(8200, $value)],
                    3 => [$this->assertSame('esn_membership_manager.weeztix_refresh_token', $key), $this->assertSame('new_refresh_tok', $value)],
                };
            });

        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->authorizeWithCode('auth_code_xyz', 'https://example.com/callback');

        $this->assertTrue($result);
    }

    public function testAuthorizeWithCodeFailsOnGuzzleException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => 'cid_test',
                'weeztix_client_secret' => 'csec_test',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Server 500', new Psr7Request('POST', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Authorization failed: @message', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->authorizeWithCode('auth_code_xyz', 'https://example.com/callback');

        $this->assertFalse($result);
    }

    public function testTokenRefreshOnExpiredToken(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => 'cid_123',
                'weeztix_client_secret' => 'sec_456',
                'weeztix_card_coupon_list_id' => 'card_list',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(2000);

        // Stored token is expired (expiry <= 2000 + 300)
        $state->method('get')->willReturnCallback(function ($key) {
            return match ($key) {
                'esn_membership_manager.weeztix_access_token' => 'refreshed_access_token',
                'esn_membership_manager.weeztix_token_expires' => 2100, // expired!
                'esn_membership_manager.weeztix_refresh_token' => 'valid_refresh_token',
                default => null,
            };
        });

        $refreshResponse = [
            'access_token' => 'refreshed_access_token',
            'expires_in' => 3600,
        ];

        // 1st request: refresh token, 2nd request: add coupon
        $httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnMap([
                [
                    'POST',
                    'https://auth.weeztix.com/tokens',
                    [
                        'form_params' => [
                            'grant_type' => 'refresh_token',
                            'client_id' => 'cid_123',
                            'client_secret' => 'sec_456',
                            'refresh_token' => 'valid_refresh_token',
                        ],
                    ],
                    new Response(200, [], json_encode($refreshResponse)),
                ],
                [
                    'PUT',
                    'https://api.weeztix.com/coupon/card_list/codes',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer refreshed_access_token',
                            'Content-Type' => 'application/json',
                            'Accept' => 'application/json',
                        ],
                        'json' => [
                            'codes' => [
                                ['code' => 'COUPON_REFRESHED'],
                            ],
                        ],
                    ],
                    new Response(200),
                ],
            ]);

        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'COUPON_REFRESHED');

        $this->assertTrue($result);
    }

    public function testRefreshTokenThrowsGuzzleExceptionReturnsNull(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'weeztix_client_id' => 'cid_123',
                'weeztix_client_secret' => 'sec_456',
                'weeztix_card_coupon_list_id' => 'card_list',
            ],
        ]);
        $httpClient = $this->createMock(ClientInterface::class);
        $state = $this->createMock(StateInterface::class);
        $time = $this->createMock(TimeInterface::class);
        $time->method('getRequestTime')->willReturn(2000);

        $state->method('get')->willReturnCallback(function ($key) {
            return match ($key) {
                'esn_membership_manager.weeztix_access_token' => 'old_token',
                'esn_membership_manager.weeztix_token_expires' => 2100, // expired
                'esn_membership_manager.weeztix_refresh_token' => 'valid_refresh_token',
                default => null,
            };
        });

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Token endpoint down', new Psr7Request('POST', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $matcher = $this->exactly(2);
        $logger->expects($matcher)
            ->method('error')
            ->willReturnCallback(function ($message, $context = []) use ($matcher) {
                match ($matcher->getInvocationCount()) {
                    1 => [$this->assertSame('Token refresh failed: @message', $message), $this->assertSame(['@message' => 'Token endpoint down'], $context)],
                    2 => [$this->assertSame('Access token could not be fetched.', $message), $this->assertEmpty($context)],
                };
            });
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new WeeztixService($configFactory, $httpClient, $state, $time, $loggerFactory);
        $result = $service->addCoupon('card', 'COUPON_1');

        $this->assertFalse($result);
    }
}

