<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Mail\BothApprovalEmail;
use Drupal\esn_membership_manager\Mail\PassApprovalEmail;
use Drupal\esn_membership_manager\Plugin\Action\ApproveApplication;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentLink;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for ApproveApplication action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\ApproveApplication
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Mail\BothApprovalEmail
 * @uses         \Drupal\esn_membership_manager\Mail\PassApprovalEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApproveApplicationTest extends MembershipManagerTestCase
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
            'switch_google_wallet' => false,
            'switch_apple_wallet' => false,
            'switch_weeztix' => false,
            'weeztix_pass_coupon_list_id' => '',
            'pass_name' => 'ESN Pass',
        ], $overrides);

        return $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => $settings,
        ]);
    }

    public function testCreateInstantiatesActionFromContainer(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            ['config.factory', 1, $this->getTestConfigFactory()],
            ['database', 1, $this->createMock(Connection::class)],
            ['esn_membership_manager.stripe_service', 1, $this->createMock(StripeService::class)],
            ['omnia.email_service', 1, $this->createMock(EmailService::class)],
            ['esn_membership_manager.weeztix_service', 1, $this->createMock(WeeztixService::class)],
            ['logger.factory', 1, $this->getLoggerFactoryMock()],
        ]);

        $action = ApproveApplication::create($container, [], 'esn_membership_manager_approve', []);
        $this->assertInstanceOf(ApproveApplication::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyApplicationDoesNothing(): void
    {
        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock()
        );

        $action->execute();
        $this->assertTrue(true);
    }

    public function testExecuteThrowsWhenNationalityIsUndetermined(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->with(ApplicationField::Nationality)->willReturn('Undetermined');

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock()
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Nationality cannot be left as Undetermined.');

        $action->execute($app);
    }

    public function testExecuteThrowsWhenApprovalStatusCannotBeApplied(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('501');
        $app->method('getValue')->with(ApplicationField::Nationality)->willReturn('Cypriot');
        $app->method('addApprovalStatus')
            ->with(ApprovalStatuses::Approved)
            ->willReturn('This application already been approved or rejected.');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id cannot be marked as approved. @issues.', [
                '@id' => '501',
                '@issues' => 'This application already been approved or rejected.',
            ]);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('This status cannot be applied.');

        $action->execute($app);
    }

    public function testExecuteHasESNcardQueryFailsThrowsException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('502');
        $app->method('setValue')->willReturnSelf();
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'French',
                ApplicationField::HasESNcard => true,
                default => null,
            };
        });
        $app->method('addApprovalStatus')->willReturn(true);

        $database = $this->createMock(Connection::class);
        $database->method('select')->willThrowException(new Exception('DB connection down'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Querying number of available ESNcards failed: @message.', [
                '@message' => 'DB connection down',
            ]);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $database,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to check ESNcard availability');

        $action->execute($app);
    }

    public function testExecuteHasESNcardZeroAvailableThrowsException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('503');
        $app->method('setValue')->willReturnSelf();
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Italian',
                ApplicationField::HasESNcard => true,
                default => null,
            };
        });
        $app->method('addApprovalStatus')->willReturn(true);

        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(0); // 0 cards!
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Application @id requested ESNcard but none are available.', ['@id' => '503']);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $database,
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No available ESNcards');

        $action->execute($app);
    }

    public function testExecuteHasESNcardStripeApiErrorThrowsException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('504');
        $app->method('setValue')->willReturnSelf();
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Spanish',
                ApplicationField::HasESNcard => true,
                ApplicationField::MobilityStatus => 'Erasmus Student',
                default => null,
            };
        });
        $app->method('addApprovalStatus')->willReturn(true);

        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(5); // 5 cards available
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $stripeException = $this->getMockForAbstractClass(ApiErrorException::class, ['Stripe network down']);
        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('createApplicationPaymentLink')
            ->with('504', false) // Erasmus Student is NOT an ESNer
            ->willThrowException($stripeException);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Stripe API error for application @id: @message', [
                '@id' => '504',
                '@message' => 'Stripe network down',
            ]);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $database,
            $stripeService,
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Stripe API Error');

        $action->execute($app);
    }

    public function testExecuteHasESNcardStripeReturnsFalsyThrowsException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('505');
        $app->method('setValue')->willReturnSelf();
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'German',
                ApplicationField::HasESNcard => true,
                ApplicationField::MobilityStatus => 'ESN Alumnus',
                default => null,
            };
        });
        $app->method('addApprovalStatus')->willReturn(true);

        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(2);
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('createApplicationPaymentLink')
            ->with('505', true) // ESN Alumnus IS an ESNer
            ->willReturn(null);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Failed to create payment link for application @id.', ['@id' => '505']);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $database,
            $stripeService,
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to create payment link');

        $action->execute($app);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithESNcardSendsBothApprovalEmail(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('506');

        $savedValues = [];
        $app->method('setValue')->willReturnCallback(function ($field, $value) use (&$savedValues, $app) {
            $key = $field instanceof ApplicationField ? $field->value : (string)$field;
            $savedValues[$key] = $value;
            return $app;
        });

        $app->method('getValue')->willReturnCallback(function ($field) use (&$savedValues) {
            $key = $field instanceof ApplicationField ? $field->value : (string)$field;
            if (isset($savedValues[$key])) {
                return $savedValues[$key];
            }
            return match ($field) {
                ApplicationField::Nationality => 'Greek',
                ApplicationField::HasESNcard => true,
                ApplicationField::MobilityStatus => 'ESN Volunteer',
                ApplicationField::Name => 'George',
                ApplicationField::Email => 'george@example.com',
                default => null,
            };
        });

        $app->method('addApprovalStatus')->willReturn(true);
        $app->expects($this->once())->method('clearPendingStatuses');
        $app->expects($this->once())->method('save');

        $database = $this->createMock(Connection::class);
        $select = $this->createMock(SelectInterface::class);
        $stmt = $this->createMock(StatementInterface::class);
        $stmt->method('fetchField')->willReturn(10);
        $select->method('execute')->willReturn($stmt);
        $database->method('select')->willReturn($select);

        $paymentLinkMock = new PaymentLink('plink_xyz');
        $paymentLinkMock->url = 'https://buy.stripe.com/test_payment_link';

        $stripeService = $this->createMock(StripeService::class);
        $stripeService->method('createApplicationPaymentLink')
            ->with('506', true)
            ->willReturn($paymentLinkMock);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'george@example.com',
                $this->callback(function (BothApprovalEmail $email) {
                    $vars = $email->getVariables();
                    $this->assertSame('George', $vars['name']);
                    $this->assertSame('https://buy.stripe.com/test_payment_link', $vars['payment_link']);
                    return true;
                })
            );

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $database,
            $stripeService,
            $emailService,
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock()
        );

        $action->execute($app);

        $this->assertArrayHasKey(ApplicationField::PassToken->value, $savedValues);
        $this->assertArrayHasKey(ApplicationField::PaymentLink->value, $savedValues);
        $this->assertSame('https://buy.stripe.com/test_payment_link', $savedValues[ApplicationField::PaymentLink->value]);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithoutESNcardAndWithWeeztixAndWalletLinks(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_google_wallet' => true,
            'switch_apple_wallet' => true,
            'switch_weeztix' => true,
            'weeztix_pass_coupon_list_id' => 'pass_list_999',
        ]);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('507');

        $savedValues = [];
        $app->method('setValue')->willReturnCallback(function ($field, $value) use (&$savedValues, $app) {
            $key = $field instanceof ApplicationField ? $field->value : (string)$field;
            $savedValues[$key] = $value;
            return $app;
        });

        $app->method('getValue')->willReturnCallback(function ($field) use (&$savedValues) {
            $key = $field instanceof ApplicationField ? $field->value : (string)$field;
            if (isset($savedValues[$key])) {
                return $savedValues[$key];
            }
            return match ($field) {
                ApplicationField::Nationality => 'Cypriot',
                ApplicationField::HasESNcard => false,
                ApplicationField::Name => 'Sophia',
                ApplicationField::Email => 'sophia@example.com',
                default => null,
            };
        });

        $app->method('addApprovalStatus')->willReturn(true);
        $app->expects($this->once())->method('clearPendingStatuses');
        $app->expects($this->once())->method('save');

        $weeztixService = $this->createMock(WeeztixService::class);
        $weeztixService->expects($this->once())
            ->method('addCoupon')
            ->with(
                'pass',
                $this->callback(function (string $token) {
                    $this->assertSame(32, strlen($token));
                    return true;
                }),
                ['applies_to_count' => 1, 'usage_count' => 5]
            );

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'sophia@example.com',
                $this->callback(function (PassApprovalEmail $email) {
                    $vars = $email->getVariables();
                    $this->assertSame('Sophia', $vars['name']);
                    $this->assertStringContainsString('add_to_google_wallet', $vars['google_wallet_link']);
                    $this->assertStringContainsString('download_apple_pass', $vars['apple_wallet_link']);
                    return true;
                })
            );

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $configFactory,
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $emailService,
            $weeztixService,
            $this->getLoggerFactoryMock()
        );

        $action->execute($app);
    }

    public function testExecuteHandlesUpdateException(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('508');
        $app->method('setValue')->willReturnSelf();
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::Nationality => 'Polish',
                ApplicationField::HasESNcard => false,
                default => null,
            };
        });
        $app->method('addApprovalStatus')->willReturn(true);
        $app->method('save')->willThrowException(new Exception('Disk error'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Updating Application @id failed: @message', [
                '@id' => '508',
                '@message' => 'Disk error',
            ]);

        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to update application');

        $action->execute($app);
    }

    public function testAccess(): void
    {
        $action = new ApproveApplication(
            [], 'approve_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(Connection::class),
            $this->createMock(StripeService::class),
            $this->createMock(EmailService::class),
            $this->createMock(WeeztixService::class),
            $this->getLoggerFactoryMock()
        );

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('approve applications')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('approve applications')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
