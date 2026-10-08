<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Service\DiditService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use GuzzleHttp\Psr7\Response;

/**
 * Tests for DiditService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\DiditService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class DiditServiceTest extends MembershipManagerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturn('https://example.com/apply/verify-id');
        $this->container->set('url_generator', $urlGenerator);
    }

    public function testCreateVerificationSessionFailsWhenNotConfigured(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => null,
                'didit_workflow_id' => null,
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Didit was not configured.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->createVerificationSession(self::TEST_EMAIL);

        $this->assertNull($result);
    }

    public function testCreateVerificationSessionFailsOnGuzzleException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
                'didit_workflow_id' => 'wf_456',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Connection timeout', new Psr7Request('POST', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Session creation failed. @error.', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->createVerificationSession(self::TEST_EMAIL);

        $this->assertNull($result);
    }

    public function testCreateVerificationSessionFailsOnNon201Status(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
                'didit_workflow_id' => 'wf_456',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $response = new Response(400, [], json_encode(['error' => 'Bad Request']));
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to create session. Error code: @code.', ['@code' => 400]);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->createVerificationSession(self::TEST_EMAIL);

        $this->assertNull($result);
    }

    public function testCreateVerificationSessionFailsOnDatabaseException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
                'didit_workflow_id' => 'wf_456',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $sessionData = [
            'session_id' => 'sess_123',
            'session_token' => 'tok_abc',
            'status' => 'initiated',
            'url' => 'https://verify.didit.me/sess_123',
        ];
        $response = new Response(201, [], json_encode($sessionData));
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willThrowException(new Exception('Deadlock detected'));

        $database->expects($this->once())
            ->method('update')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($update);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to save session. Error code: @error.', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->createVerificationSession(self::TEST_EMAIL);

        $this->assertNull($result);
    }

    public function testCreateVerificationSessionSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
                'didit_workflow_id' => 'wf_456',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $sessionData = [
            'session_id' => 'sess_999',
            'session_token' => 'tok_xyz',
            'status' => 'initiated',
            'url' => 'https://verify.didit.me/sess_999',
        ];
        $response = new Response(201, [], json_encode($sessionData));
        $httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                'https://verification.didit.me/v3/session',
                $this->callback(function ($options) {
                    $this->assertEquals('api_key_123', $options['headers']['x-api-key']);
                    $body = json_decode($options['body'], true);
                    $this->assertEquals('wf_456', $body['workflow_id']);
                    $this->assertEquals('https://example.com/apply/verify-id', $body['callback']);
                    $this->assertEquals('initiator', $body['callback_method']);
                    return true;
                })
            )
            ->willReturn($response);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with([
                'didit_session_id' => 'sess_999',
                'didit_session_token' => 'tok_xyz',
                'didit_status' => 'initiated',
            ])
            ->willReturnSelf();
        $update->expects($this->once())
            ->method('condition')
            ->with('email', self::TEST_EMAIL)
            ->willReturnSelf();
        $update->expects($this->once())
            ->method('execute')
            ->willReturn(1);

        $database->expects($this->once())
            ->method('update')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($update);

        $loggerFactory = $this->getLoggerFactoryMock();

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->createVerificationSession(self::TEST_EMAIL);

        $this->assertEquals('https://verify.didit.me/sess_999', $result);
    }

    public function testGetSessionSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $decisionData = ['status' => 'approved', 'decision' => 'accept'];
        $response = new Response(200, [], json_encode($decisionData));

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://verification.didit.me/v3/session/sess_123/decision', [
                'headers' => ['x-api-key' => 'api_key_123'],
            ])
            ->willReturn($response);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getSession('sess_123');

        $this->assertEquals($decisionData, $result);
    }

    public function testGetSessionReturnsNullOnGuzzleException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Server error', new Psr7Request('GET', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Session retrieval failed. @error.', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getSession('sess_123');

        $this->assertNull($result);
    }

    public function testGetSessionReturnsNullOnNon200Status(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $response = new Response(404, [], 'Not found');
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getSession('sess_not_found');

        $this->assertNull($result);
    }

    public function testDeleteSessionSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $response = new Response(204);
        $httpClient->expects($this->once())
            ->method('request')
            ->with('DELETE', 'https://verification.didit.me/v3/session/sess_123/delete', [
                'headers' => ['x-api-key' => 'api_key_123'],
            ])
            ->willReturn($response);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->deleteSession('sess_123');

        $this->assertTrue($result);
    }

    public function testDeleteSessionFailsOnNon204Status(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $response = new Response(500);
        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->deleteSession('sess_123');

        $this->assertFalse($result);
    }

    public function testDeleteSessionFailsOnException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Network failure', new Psr7Request('DELETE', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Session deletion failed. @error.', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->deleteSession('sess_123');

        $this->assertFalse($result);
    }

    public function testGetPDFSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $pdfContent = '%PDF-1.4 sample content';
        $response = new Response(200, [], $pdfContent);

        $httpClient->expects($this->once())
            ->method('request')
            ->with('GET', 'https://verification.didit.me/v3/session/sess_123/generate-pdf', [
                'headers' => ['x-api-key' => 'api_key_123'],
            ])
            ->willReturn($response);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getPDF('sess_123');

        $this->assertEquals($pdfContent, $result);
    }

    public function testGetPDFFailsOnNon200(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);
        $loggerFactory = $this->getLoggerFactoryMock();

        $httpClient->expects($this->once())
            ->method('request')
            ->willReturn(new Response(404));

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getPDF('sess_404');
        $this->assertNull($result);
    }

    public function testGetPDFFailsOnException(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'didit_api_key' => 'api_key_123',
            ],
        ]);
        $database = $this->createMock(Connection::class);
        $httpClient = $this->createMock(ClientInterface::class);

        $httpClient->expects($this->once())
            ->method('request')
            ->willThrowException(new RequestException('Timeout', new Psr7Request('GET', 'test')));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Session PDF generation failed. @error.', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new DiditService($configFactory, $database, $httpClient, $loggerFactory);
        $result = $service->getPDF('sess_error');
        $this->assertNull($result);
    }
}
