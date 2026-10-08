<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Plugin\Action;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Mail\GuestPassApprovalEmail;
use Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unit tests for ApproveGuestPass action.
 *
 * @covers       \Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Mail\GuestPassApprovalEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApproveGuestPassTest extends MembershipManagerTestCase
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
            'guest_pass_name' => 'ESN Guest Pass',
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
            ['omnia.email_service', 1, $this->createMock(EmailService::class)],
            ['logger.factory', 1, $this->getLoggerFactoryMock()],
        ]);

        $action = ApproveGuestPass::create($container, [], 'esn_membership_manager_approve_guest', []);
        $this->assertInstanceOf(ApproveGuestPass::class, $action);
    }

    /**
     * @throws Exception
     */
    public function testExecuteWithEmptyGuestPassDoesNothing(): void
    {
        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new ApproveGuestPass(
            [], 'approve_guest_id', [],
            $this->getTestConfigFactory(),
            $emailService,
            $this->getLoggerFactoryMock()
        );

        $action->execute();
        $this->assertTrue(true);
    }

    /**
     * @throws Exception
     */
    public function testExecuteSuccessWithWalletLinksAndEmail(): void
    {
        $configFactory = $this->getTestConfigFactory([
            'switch_google_wallet' => true,
            'switch_apple_wallet' => true,
        ]);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('301');

        $savedValues = [];
        $guestPass->method('setValue')->willReturnCallback(function ($field, $value) use (&$savedValues, $guestPass) {
            $key = $field instanceof GuestPassField ? $field->value : (string)$field;
            $savedValues[$key] = $value;
            return $guestPass;
        });

        $guestPass->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                GuestPassField::Name => 'Alex Guest',
                GuestPassField::Email => 'alex@example.com',
                default => null,
            };
        });

        $guestPass->expects($this->once())->method('save');

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('notice')
            ->with('Approved guest pass @id.', ['@id' => '301']);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->once())
            ->method('send')
            ->with(
                'alex@example.com',
                $this->callback(function (GuestPassApprovalEmail $email) {
                    $vars = $email->getVariables();
                    $this->assertSame('Alex Guest', $vars['name']);
                    $this->assertStringStartsWith('GUEST', $vars['pass_token']);
                    $this->assertStringContainsString('add_to_google_wallet', $vars['google_wallet_link']);
                    $this->assertStringContainsString('download_apple_pass', $vars['apple_wallet_link']);
                    return true;
                })
            );

        $action = new ApproveGuestPass([], 'approve_guest_id', [], $configFactory, $emailService, $this->getLoggerFactoryMock($logger));
        $action->execute($guestPass);

        $this->assertArrayHasKey(GuestPassField::PassToken->value, $savedValues);
        $this->assertStringStartsWith('GUEST', $savedValues[GuestPassField::PassToken->value]);
        $this->assertArrayHasKey(GuestPassField::DateApproved->value, $savedValues);
    }

    public function testExecuteHandlesSaveException(): void
    {
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->method('id')->willReturn('302');
        $guestPass->method('setValue')->willReturnSelf();
        $guestPass->method('save')->willThrowException(new Exception('Failed to write guest pass'));

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Updating Guest Pass @id failed: @message', [
                '@id' => '302',
                '@message' => 'Failed to write guest pass',
            ]);

        $emailService = $this->createMock(EmailService::class);
        $emailService->expects($this->never())->method('send');

        $action = new ApproveGuestPass(
            [], 'approve_guest_id', [],
            $this->getTestConfigFactory(),
            $emailService,
            $this->getLoggerFactoryMock($logger)
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Failed to update Guest Pass.');

        $action->execute($guestPass);
    }

    public function testAccess(): void
    {
        $action = new ApproveGuestPass(
            [], 'approve_guest_id', [],
            $this->getTestConfigFactory(),
            $this->createMock(EmailService::class),
            $this->getLoggerFactoryMock()
        );

        $accountWithPerm = $this->createMock(AccountInterface::class);
        $accountWithPerm->method('hasPermission')->with('approve guest passes')->willReturn(true);

        $accountWithoutPerm = $this->createMock(AccountInterface::class);
        $accountWithoutPerm->method('hasPermission')->with('approve guest passes')->willReturn(false);

        $this->assertTrue($action->access(null, $accountWithPerm));
        $this->assertFalse($action->access(null, $accountWithoutPerm));

        $result = $action->access(null, $accountWithPerm, true);
        $this->assertInstanceOf(AccessResultInterface::class, $result);
        $this->assertTrue($result->isAllowed());
    }
}
