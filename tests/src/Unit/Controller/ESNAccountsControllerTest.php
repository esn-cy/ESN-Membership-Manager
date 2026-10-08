<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_accounts_api\Entity\Organisation;
use Drupal\esn_membership_manager\Controller\ESNAccountsController;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\esn_membership_manager\Controller\ESNAccountsController
 * @group esn_membership_manager
 */
class ESNAccountsControllerTest extends MembershipManagerTestCase
{
    private Connection $database;
    private Client $httpClient;
    private EntityTypeManagerInterface $entityTypeManager;
    private EntityStorageInterface $organisationStorage;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        spl_autoload_register(function ($class) {
            if (str_starts_with($class, 'Drupal\\esn_accounts_api\\')) {
                $file = dirname(__DIR__, 4) . '/vendor/esn/esn_accounts_api/src/' . str_replace('\\', '/', substr($class, 24)) . '.php';
                if (file_exists($file)) {
                    require_once $file;
                }
            }
        });

        $this->database = $this->createMock(Connection::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->organisationStorage = $this->createMock(EntityStorageInterface::class);

        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->with('esn_organisation')
            ->willReturn($this->organisationStorage);

        $this->logger = $this->createMock(LoggerChannelInterface::class);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')
            ->willReturnCallback(fn($name) => '/dummy/' . $name);
        $this->container->set('url_generator', $urlGenerator);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock($this->logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) use ($configFactory, $loggerFactory) {
                return match ($id) {
                    'database' => $this->database,
                    'http_client' => $this->httpClient,
                    'entity_type.manager' => $this->entityTypeManager,
                    'config.factory' => $configFactory,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = ESNAccountsController::create($container);
        $this->assertInstanceOf(ESNAccountsController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackEmptyTicketOrTokenReturnsRedirect(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => '']);
        $response = $controller->callback($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.apply', $response->getTargetUrl());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackApplicationNotFoundReturnsRedirect(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(false);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertEquals('/dummy/esn_membership_manager.apply', $response->getTargetUrl());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackCasNoRolesReturnsRedirectAndUpdatesStatus(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with(['esn_status' => 'No Roles'])
            ->willReturnSelf();
        $update->method('condition')->with('id', 10)->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $xml = <<<XML
<cas:serviceResponse xmlns:cas='http://www.yale.edu/tp/cas'>
    <cas:authenticationSuccess>
        <cas:user>john.doe</cas:user>
        <cas:attributes>
            <cas:first>John</cas:first>
            <cas:last>Doe</cas:last>
        </cas:attributes>
    </cas:authenticationSuccess>
</cas:serviceResponse>
XML;

        $this->httpClient->expects($this->once())
            ->method('get')
            ->willReturn(new Response(200, [], $xml));

        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackCasSuccessNationalVolunteer(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with([
                'status_name' => 'John',
                'status_surname' => 'Doe',
                'status_mobility' => 'ESN Volunteer',
                'status_host_institution' => 'ESN Cyprus',
                'esn_status' => 'Success'
            ])
            ->willReturnSelf();
        $update->method('condition')->with('id', 10)->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $nationalOrg = $this->createMock(Organisation::class);
        $nationalOrg->method('getCountryCode')->willReturn('CY');
        $nationalOrg->method('getTitle')->willReturn('ESN Cyprus');

        $this->organisationStorage->method('load')
            ->with(100)
            ->willReturn($nationalOrg);
        $section = $this->createMock(Organisation::class);
        $section->method('getCode')->willReturn('SEC_CY');
        $this->organisationStorage->method('loadByProperties')
            ->willReturn([$section]);

        $xml = <<<XML
<cas:serviceResponse xmlns:cas='http://www.yale.edu/tp/cas'>
    <cas:authenticationSuccess>
        <cas:user>john.doe</cas:user>
        <cas:attributes>
            <cas:first>John</cas:first>
            <cas:last>Doe</cas:last>
            <cas:extended_roles>National.member:CY</cas:extended_roles>
        </cas:attributes>
    </cas:authenticationSuccess>
</cas:serviceResponse>
XML;

        $this->httpClient->expects($this->once())
            ->method('get')
            ->willReturn(new Response(200, [], $xml));

        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [
                'section_mode' => false,
                'national_organisation_id' => 100,
            ],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackCasForeignRoles(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with(['esn_status' => 'Foreign Roles'])
            ->willReturnSelf();
        $update->method('condition')->with('id', 10)->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $nationalOrg = $this->createMock(Organisation::class);
        $nationalOrg->method('getCountryCode')->willReturn('CY');

        $this->organisationStorage->method('load')
            ->with(100)
            ->willReturn($nationalOrg);
        $this->organisationStorage->method('loadByProperties')
            ->willReturn([]);

        $xml = <<<XML
<cas:serviceResponse xmlns:cas='http://www.yale.edu/tp/cas'>
    <cas:authenticationSuccess>
        <cas:user>foreign.user</cas:user>
        <cas:attributes>
            <cas:first>Foreign</cas:first>
            <cas:last>User</cas:last>
            <cas:extended_roles>National.member:FR</cas:extended_roles>
        </cas:attributes>
    </cas:authenticationSuccess>
</cas:serviceResponse>
XML;

        $this->httpClient->expects($this->once())
            ->method('get')
            ->willReturn(new Response(200, [], $xml));

        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [
                'section_mode' => false,
                'national_organisation_id' => 100,
            ],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackSelectException(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willThrowException(new Exception('DB down'));
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['esn_status' => 'Failed'])->willReturnSelf();
        $update->method('condition')->with('esn_token', 'token-456')->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ESN Accounts authentication failed. @error', ['@error' => 'DB down']);

        $configFactory = $this->getConfigFactoryStub([OmniaSettings::CONFIG_NAME => []]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackSelectExceptionWithNestedUpdateException(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willThrowException(new Exception('DB down'));
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willThrowException(new Exception('Update failed'));
        $this->database->method('update')->willReturn($update);

        $this->logger->expects($this->exactly(2))->method('error');

        $configFactory = $this->getConfigFactoryStub([OmniaSettings::CONFIG_NAME => []]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackLocalRolesWithSectionModeTrueAndMalformedRole(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with([
                'status_name' => 'John',
                'status_surname' => 'Doe',
                'status_mobility' => 'ESN Volunteer',
                'status_host_institution' => 'ESN Nicosia',
                'esn_status' => 'Success',
            ])
            ->willReturnSelf();
        $update->method('condition')->with('id', 10)->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $nationalOrg = $this->createMock(Organisation::class);
        $nationalOrg->method('getCountryCode')->willReturn('CY');

        $section = $this->createMock(Organisation::class);
        $section->method('getCode')->willReturn('SEC_NIC');
        $section->method('getTitle')->willReturn('ESN Nicosia');

        $this->organisationStorage->method('load')
            ->willReturnCallback(function ($id) use ($nationalOrg, $section) {
                return match ($id) {
                    100 => $nationalOrg,
                    200 => $section,
                    default => null,
                };
            });

        $xml = <<<XML
<cas:serviceResponse xmlns:cas='http://www.yale.edu/tp/cas'>
    <cas:authenticationSuccess>
        <cas:user>john.doe</cas:user>
        <cas:attributes>
            <cas:first>John</cas:first>
            <cas:last>Doe</cas:last>
            <cas:extended_roles>NoColonRole</cas:extended_roles>
            <cas:extended_roles>Local.alumnus:SEC_NIC</cas:extended_roles>
            <cas:extended_roles>Local.member:SEC_NIC</cas:extended_roles>
        </cas:attributes>
    </cas:authenticationSuccess>
</cas:serviceResponse>
XML;

        $this->httpClient->method('get')->willReturn(new Response(200, [], $xml));

        $configFactory = $this->getConfigFactoryStub([
            OmniaSettings::CONFIG_NAME => [
                'section_mode' => true,
                'national_organisation_id' => 100,
                'organisation_id' => 200,
            ],
        ]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackCasAuthFailureThrowsAndUpdatesFailed(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->with(['esn_status' => 'Failed'])->willReturnSelf();
        $update->method('condition')->with('id', 10)->willReturnSelf();
        $update->expects($this->once())->method('execute');
        $this->database->method('update')->willReturn($update);

        $xml = <<<XML
<cas:serviceResponse xmlns:cas='http://www.yale.edu/tp/cas'>
    <cas:authenticationFailure code="INVALID_TICKET">Ticket invalid</cas:authenticationFailure>
</cas:serviceResponse>
XML;
        $this->httpClient->method('get')->willReturn(new Response(200, [], $xml));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('ESN Accounts authentication failed. @error', ['@error' => 'Failed login.']);

        $configFactory = $this->getConfigFactoryStub([OmniaSettings::CONFIG_NAME => []]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCallbackCasHttpFailureNestedUpdateException(): void
    {
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchAssoc')->willReturn(['id' => 10, 'esn_token' => 'token-456']);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $this->database->method('select')->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willThrowException(new Exception('Update Failed status error'));
        $this->database->method('update')->willReturn($update);

        $this->httpClient->method('get')->willThrowException(new Exception('Network timeout'));

        $this->logger->expects($this->exactly(2))->method('error');

        $configFactory = $this->getConfigFactoryStub([OmniaSettings::CONFIG_NAME => []]);

        $controller = new ESNAccountsController(
            $this->database,
            $this->httpClient,
            $this->entityTypeManager,
            $configFactory,
            $this->getLoggerFactoryMock($this->logger)
        );

        $request = new Request(['ticket' => 'ST-123', 'token' => 'token-456']);
        $response = $controller->callback($request);
        $this->assertInstanceOf(RedirectResponse::class, $response);
    }
}
