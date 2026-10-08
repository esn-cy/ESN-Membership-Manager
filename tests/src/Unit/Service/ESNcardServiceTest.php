<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Insert;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Transaction;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Mail\BacklogEmail;
use Drupal\esn_membership_manager\Mail\CardAssignmentEmail;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;

/**
 * Tests for ESNcardService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\ESNcardService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Mail\BacklogEmail
 * @uses         \Drupal\esn_membership_manager\Mail\CardAssignmentEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ESNcardServiceTest extends MembershipManagerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturnCallback(function ($route, $params) {
            return 'https://example.com/' . $route . '/' . ($params['identifier'] ?? '');
        });
        $this->container->set('url_generator', $urlGenerator);
    }

    protected function getTestConfigFactory(array $overrides = []): ConfigFactoryInterface
    {
        $settings = array_merge([
            'admin_email_address' => 'admin@esncy.org',
            'switch_weeztix' => false,
            'switch_google_sheets' => false,
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
            'weeztix_card_coupon_list_id' => 'list_123',
        ], $overrides);

        return $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => $settings,
        ]);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddESNcardsValidationAndIssueDetection(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);
        $applicationStorage->method('getBacklogged')->willReturn([]);

        $loggerFactory = $this->getLoggerFactoryMock();
        $stripeService = $this->createMock(StripeService::class);
        $emailService = $this->createMock(EmailService::class);
        $weeztixService = $this->createMock(WeeztixService::class);
        $googleService = $this->createMock(GoogleService::class);

        // Card 1: empty string -> 'empty'
        // Card 2: whitespace -> 'empty'
        // Card 3: invalid format ('123') -> 'invalid'
        // Card 4: valid format ('1234567ABCD') but duplicate in DB -> 'duplicate'
        // Card 5: valid format ('7654321WXYZ'), not duplicate -> inserted successfully
        $select = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmtCountDup = $this->createMock(StatementInterface::class);
        $stmtCountDup->method('fetchField')->willReturn(1); // duplicate!
        $countQuery->method('execute')->willReturn($stmtCountDup);

        $selectNotDup = $this->createMock(SelectInterface::class);
        $countQueryNotDup = $this->createMock(SelectInterface::class);
        $stmtNotDup = $this->createMock(StatementInterface::class);
        $stmtNotDup->method('fetchField')->willReturn(0); // not duplicate!
        $countQueryNotDup->method('execute')->willReturn($stmtNotDup);

        $database->expects($this->exactly(2))
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturnOnConsecutiveCalls($select, $selectNotDup);

        $select->method('condition')->with('number', '1234567ABCD')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        $selectNotDup->method('condition')->with('number', '7654321WXYZ')->willReturnSelf();
        $selectNotDup->method('countQuery')->willReturn($countQueryNotDup);

        $insert = $this->createMock(Insert::class);
        $insert->expects($this->once())
            ->method('fields')
            ->with(['number' => '7654321WXYZ', 'assigned' => 0])
            ->willReturnSelf();
        $insert->expects($this->once())->method('execute')->willReturn(1);

        $database->expects($this->once())
            ->method('insert')
            ->with('esn_membership_manager_cards')
            ->willReturn($insert);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $emailService,
            $weeztixService,
            $googleService
        );

        $cards = ['', '   ', '123_INVALID', '1234567ABCD', '7654321WXYZ'];
        $issues = $service->addESNcards($cards);

        $this->assertCount(4, $issues);
        $this->assertEquals(['issue' => 'empty', 'number' => ''], $issues[0]);
        $this->assertEquals(['issue' => 'empty', 'number' => ''], $issues[1]);
        $this->assertEquals(['issue' => 'invalid', 'number' => '123_INVALID'], $issues[2]);
        $this->assertEquals(['issue' => 'duplicate', 'number' => '1234567ABCD'], $issues[3]);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddESNcardsProcessesBacklog(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);

        $loggerFactory = $this->getLoggerFactoryMock();
        $stripeService = $this->createMock(StripeService::class);
        $emailService = $this->createMock(EmailService::class);
        $weeztixService = $this->createMock(WeeztixService::class);
        $googleService = $this->createMock(GoogleService::class);

        // Select for duplicate check returns 0
        $select = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmtCount = $this->createMock(StatementInterface::class);
        $stmtCount->method('fetchField')->willReturn(0);
        $countQuery->method('execute')->willReturn($stmtCount);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        // Insert
        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturn(1);

        // Select next available card for backlog
        $selectNext = $this->createMock(SelectInterface::class);
        $stmtNext = $this->createMock(StatementInterface::class);
        $stmtNext->method('fetchField')->willReturn('1234567ABCD');
        $selectNext->method('fields')->willReturnSelf();
        $selectNext->method('condition')->willReturnSelf();
        $selectNext->method('orderBy')->willReturnSelf();
        $selectNext->method('range')->willReturnSelf();
        $selectNext->method('forUpdate')->willReturnSelf();
        $selectNext->method('execute')->willReturn($stmtNext);

        $database->method('select')->willReturnOnConsecutiveCalls($select, $selectNext);
        $database->method('insert')->willReturn($insert);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $database->method('update')->willReturn($update);

        $transaction = $this->createMock(Transaction::class);
        $database->method('startTransaction')->willReturn($transaction);

        // Backlogged application
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('500');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => 'BACKLOGGED-MANUAL',
                ApplicationField::Name => 'Alice',
                ApplicationField::Email => 'alice@example.com',
                default => null,
            };
        });

        $app->expects($this->once())
            ->method('setValue')
            ->with(ApplicationField::ESNcardNumber, '1234567ABCD');
        $app->expects($this->once())->method('save');

        $applicationStorage->method('getBacklogged')->willReturn([$app]);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $emailService,
            $weeztixService,
            $googleService
        );

        $issues = $service->addESNcards(['1234567ABCD']);
        $this->assertEmpty($issues);
    }

    /**
     * @throws Exception
     */
    public function testAssignESNcardNumberSuccess(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);
        $loggerFactory = $this->getLoggerFactoryMock();

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn('9999999ABCD');

        $select->method('fields')->with('e', ['number'])->willReturnSelf();
        $select->method('condition')->with('assigned', 0)->willReturnSelf();
        $select->method('orderBy')->with('id')->willReturnSelf();
        $select->method('range')->with(0, 1)->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);

        $database->expects($this->once())
            ->method('select')
            ->with('esn_membership_manager_cards', 'e')
            ->willReturn($select);

        $update = $this->createMock(Update::class);
        $update->expects($this->once())
            ->method('fields')
            ->with(['assigned' => 1])
            ->willReturnSelf();
        $update->expects($this->once())
            ->method('condition')
            ->with('number', '9999999ABCD')
            ->willReturnSelf();
        $update->expects($this->once())->method('execute')->willReturn(1);

        $database->expects($this->once())
            ->method('update')
            ->with('esn_membership_manager_cards')
            ->willReturn($update);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('101');

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $result = $service->assignESNcardNumber($app, false);
        $this->assertEquals('9999999ABCD', $result);
    }

    /**
     * @throws Exception
     */
    public function testAssignESNcardNumberNoCardsAvailableSendsEmailOnce(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);

        // First time running out of cards: countBacklogged is 0 -> sends email!
        $applicationStorage->method('countBacklogged')->willReturn(0);

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(false); // No cards!
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('range')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with('admin@esncy.org', $this->isInstanceOf(BacklogEmail::class));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('No available ESNcard numbers left to assign.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $app = $this->createMock(ApplicationInterface::class);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $emailService,
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $resultManual = $service->assignESNcardNumber($app, true);
        $this->assertEquals('BACKLOGGED-MANUAL', $resultManual);
    }

    /**
     * @throws Exception
     */
    public function testAssignESNcardNumberAlreadyBackloggedDoesNotResendEmail(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);

        // Already backlogged > 0 -> should NOT send email
        $applicationStorage->method('countBacklogged')->willReturn(3);

        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(null);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();
        $select->method('orderBy')->willReturnSelf();
        $select->method('range')->willReturnSelf();
        $select->method('forUpdate')->willReturnSelf();
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $loggerFactory = $this->getLoggerFactoryMock();
        $app = $this->createMock(ApplicationInterface::class);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $emailService,
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $resultAuto = $service->assignESNcardNumber($app, false);
        $this->assertEquals('BACKLOGGED', $resultAuto);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAssignESNcardNumberThrowsOnDatabaseException(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $database->method('select')->willThrowException(new Exception('Connection lost'));

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to assign ESNcard number: @message', $this->anything());
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $app = $this->createMock(ApplicationInterface::class);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to get next available ESNcard number');

        $service->assignESNcardNumber($app, false);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPostAssignmentSkipsWhenBacklogged(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_weeztix' => true,
            'switch_google_sheets' => true,
        ]);
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));
        $loggerFactory = $this->getLoggerFactoryMock();

        $stripeService = $this->createMock(StripeService::class);
        $emailService = $this->createMock(EmailService::class);
        $weeztixService = $this->createMock(WeeztixService::class);
        $googleService = $this->createMock(GoogleService::class);

        $weeztixService->expects($this->never())->method('addCoupon');
        $googleService->expects($this->never())->method('appendRow');
        $emailService->expects($this->never())->method('send');

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::ESNcardNumber)->willReturn('BACKLOGGED');

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $emailService,
            $weeztixService,
            $googleService
        );

        $service->postAssignment($app, false);
        $this->assertTrue(true);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPostAssignmentExecutesAllIntegrationsForStripe(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_weeztix' => true,
            'weeztix_card_coupon_list_id' => 'card_coupons_list',
            'switch_google_sheets' => true,
            'switch_google_wallet' => true,
            'switch_apple_wallet' => true,
        ]);
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));
        $loggerFactory = $this->getLoggerFactoryMock();

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('getPriceAmount')
            ->with(true) // ESN Volunteer is an ESNer
            ->willReturn(15.0);

        $weeztixService = $this->createMock(WeeztixService::class);
        $weeztixService->expects($this->once())
            ->method('addCoupon')
            ->with('card', '1234567ABCD', ['applies_to_count' => 1, 'usage_count' => 5]);

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('appendRow')
            ->with($this->callback(function (array $row) {
                $this->assertEquals('John Doe', $row['name']);
                $this->assertEquals('1234567ABCD', $row['card_number']);
                $this->assertEquals('University of Cyprus', $row['host']);
                $this->assertEquals('Cypriot', $row['nationality']);
                $this->assertEquals('Stripe', $row['mop']);
                $this->assertEquals('15.00', $row['amount']);
                return true;
            }));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'john@example.com',
                $this->isInstanceOf(CardAssignmentEmail::class)
            );

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFullName')->willReturn('John Doe');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => '1234567ABCD',
                ApplicationField::MobilityStatus => 'ESN Volunteer',
                ApplicationField::HostInstitution => 'University of Cyprus',
                ApplicationField::Nationality => 'Cypriot',
                ApplicationField::Name => 'John',
                ApplicationField::Email => 'john@example.com',
                default => null,
            };
        });

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $emailService,
            $weeztixService,
            $googleService
        );

        $service->postAssignment($app, false);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPostAssignmentManualPaymentSetsManualMop(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_google_sheets' => true,
        ]);
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));
        $loggerFactory = $this->getLoggerFactoryMock();

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->never())->method('getPriceAmount');

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('appendRow')
            ->with($this->callback(function (array $row) {
                $this->assertEquals('Manual', $row['mop']);
                $this->assertEquals('Unknown', $row['amount']);
                return true;
            }));

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())->method('send');

        $app = $this->createMock(ApplicationInterface::class);

        $app->method('getFullName')->willReturn('Maria Ioannou');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => '7654321WXYZ',
                ApplicationField::HostInstitution => 'Cyprus University of Technology',
                ApplicationField::Nationality => 'Greek',
                ApplicationField::Name => 'Maria',
                ApplicationField::Email => 'maria@example.com',
                default => null,
            };
        });

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $emailService,
            $this->createMock(WeeztixService::class),
            $googleService
        );

        $service->postAssignment($app, true);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddESNcardsHandlesDatabaseExceptionOnInsert(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);
        $applicationStorage->method('getBacklogged')->willReturn([]);

        $select = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmtCount = $this->createMock(StatementInterface::class);
        $stmtCount->method('fetchField')->willReturn(0);
        $countQuery->method('execute')->willReturn($stmtCount);
        $select->method('condition')->willReturnSelf();
        $select->method('countQuery')->willReturn($countQuery);

        $database->method('select')->willReturn($select);

        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willThrowException(new Exception('Disk full'));
        $database->method('insert')->willReturn($insert);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to insert ESNcard @card: @message', [
                '@card' => '1234567ABCD',
                '@message' => 'Disk full',
            ]);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $issues = $service->addESNcards(['1234567ABCD']);
        $this->assertCount(1, $issues);
        $this->assertEquals(['issue' => 'database', 'number' => '1234567ABCD'], $issues[0]);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddESNcardsSlicesBacklogWhenApplicationsExceedInsertedCount(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);

        $selectDup = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmtCount = $this->createMock(StatementInterface::class);
        $stmtCount->method('fetchField')->willReturn(0);
        $countQuery->method('execute')->willReturn($stmtCount);
        $selectDup->method('condition')->willReturnSelf();
        $selectDup->method('countQuery')->willReturn($countQuery);

        $selectNext = $this->createMock(SelectInterface::class);
        $stmtNext = $this->createMock(StatementInterface::class);
        $stmtNext->method('fetchField')->willReturn('1234567ABCD');
        $selectNext->method('fields')->willReturnSelf();
        $selectNext->method('condition')->willReturnSelf();
        $selectNext->method('orderBy')->willReturnSelf();
        $selectNext->method('range')->willReturnSelf();
        $selectNext->method('forUpdate')->willReturnSelf();
        $selectNext->method('execute')->willReturn($stmtNext);

        $database->method('select')->willReturnOnConsecutiveCalls($selectDup, $selectNext);

        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturn(1);
        $database->method('insert')->willReturn($insert);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $database->method('update')->willReturn($update);

        $transaction = $this->createMock(Transaction::class);
        $database->method('startTransaction')->willReturn($transaction);

        $app1 = $this->createMock(ApplicationInterface::class);
        $app1->method('id')->willReturn('1');
        $app1->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => 'BACKLOGGED',
                ApplicationField::Name => 'First App',
                ApplicationField::Email => 'first@example.com',
                default => null,
            };
        });
        $app1->expects($this->once())->method('setValue')->with(ApplicationField::ESNcardNumber, '1234567ABCD');
        $app1->expects($this->once())->method('save');

        $app2 = $this->createMock(ApplicationInterface::class);
        $app2->expects($this->never())->method('setValue');
        $app2->expects($this->never())->method('save');

        $app3 = $this->createMock(ApplicationInterface::class);
        $app3->expects($this->never())->method('setValue');
        $app3->expects($this->never())->method('save');

        $applicationStorage->method('getBacklogged')->willReturn([$app1, $app2, $app3]);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $this->getLoggerFactoryMock(),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $issues = $service->addESNcards(['1234567ABCD']);
        $this->assertEmpty($issues);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAddESNcardsHandlesApplicationUpdateExceptionRollsBackAndLogs(): void
    {
        $configFactory = $this->getTestConfigFactory();
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($applicationStorage);

        $selectDup = $this->createMock(SelectInterface::class);
        $countQuery = $this->createMock(SelectInterface::class);
        $stmtCount = $this->createMock(StatementInterface::class);
        $stmtCount->method('fetchField')->willReturn(0);
        $countQuery->method('execute')->willReturn($stmtCount);
        $selectDup->method('condition')->willReturnSelf();
        $selectDup->method('countQuery')->willReturn($countQuery);

        $selectNext = $this->createMock(SelectInterface::class);
        $stmtNext = $this->createMock(StatementInterface::class);
        $stmtNext->method('fetchField')->willReturn('1234567ABCD');
        $selectNext->method('fields')->willReturnSelf();
        $selectNext->method('condition')->willReturnSelf();
        $selectNext->method('orderBy')->willReturnSelf();
        $selectNext->method('range')->willReturnSelf();
        $selectNext->method('forUpdate')->willReturnSelf();
        $selectNext->method('execute')->willReturn($stmtNext);

        $database->method('select')->willReturnOnConsecutiveCalls($selectDup, $selectNext);

        $insert = $this->createMock(Insert::class);
        $insert->method('fields')->willReturnSelf();
        $insert->method('execute')->willReturn(1);
        $database->method('insert')->willReturn($insert);

        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $database->method('update')->willReturn($update);

        $transaction = $this->createMock(Transaction::class);
        $transaction->expects($this->once())->method('rollBack');
        $database->method('startTransaction')->willReturn($transaction);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('42');
        $app->method('getValue')->with(ApplicationField::ESNcardNumber)->willReturn('BACKLOGGED');
        $app->method('save')->willThrowException(new Exception('Database lock timeout'));

        $applicationStorage->method('getBacklogged')->willReturn([$app]);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to update application @id: @message', [
                '@id' => '42',
                '@message' => 'Database lock timeout',
            ]);
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->createMock(GoogleService::class)
        );

        $issues = $service->addESNcards(['1234567ABCD']);
        $this->assertEmpty($issues);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPostAssignmentStripePriceFalsySetsUnknownPrice(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_google_sheets' => true,
        ]);
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));
        $loggerFactory = $this->getLoggerFactoryMock();

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('getPriceAmount')
            ->with(false)
            ->willReturn(null);

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('appendRow')
            ->with($this->callback(function (array $row) {
                $this->assertEquals('Stripe', $row['mop']);
                $this->assertEquals('Unknown', $row['amount']);
                return true;
            }));

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFullName')->willReturn('Bob Smith');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => '1234567ABCD',
                ApplicationField::MobilityStatus => 'Erasmus Student',
                ApplicationField::HostInstitution => 'University of Cyprus',
                ApplicationField::Nationality => 'British',
                ApplicationField::Name => 'Bob',
                ApplicationField::Email => 'bob@example.com',
                default => null,
            };
        });

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $googleService
        );

        $service->postAssignment($app, false);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPostAssignmentStripePriceThrowsExceptionSetsUnknownPrice(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_google_sheets' => true,
        ]);
        $database = $this->createMock(Connection::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturn($this->createMock(ApplicationStorage::class));
        $loggerFactory = $this->getLoggerFactoryMock();

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->expects($this->once())
            ->method('getPriceAmount')
            ->willThrowException(new Exception('Stripe API network timeout'));

        $googleService = $this->createMock(GoogleService::class);
        $googleService->expects($this->once())
            ->method('appendRow')
            ->with($this->callback(function (array $row) {
                $this->assertEquals('Stripe', $row['mop']);
                $this->assertEquals('Unknown', $row['amount']);
                return true;
            }));

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getFullName')->willReturn('Charlie Brown');
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::ESNcardNumber => '1234567ABCD',
                ApplicationField::MobilityStatus => 'ESN Alumnus',
                ApplicationField::HostInstitution => 'University of Cyprus',
                ApplicationField::Nationality => 'French',
                ApplicationField::Name => 'Charlie',
                ApplicationField::Email => 'charlie@example.com',
                default => null,
            };
        });

        $service = new ESNcardService(
            $configFactory,
            $database,
            $entityTypeManager,
            $loggerFactory,
            $stripeService,
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $googleService
        );

        $service->postAssignment($app, false);
    }
}
