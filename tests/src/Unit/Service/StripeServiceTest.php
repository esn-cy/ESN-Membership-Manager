<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Stripe\Event;
use Stripe\PaymentLink;
use Stripe\Price;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests for StripeService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\StripeService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class StripeServiceTest extends MembershipManagerTestCase
{
    /**
     * @throws Exception
     */
    public function testCreateApplicationPaymentLinkFailsWhenPriceNotConfigured(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => null,
            ],
        ]);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Stripe Price ID for ESNcard is not configured.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new StripeService($configFactory, $loggerFactory);
        $result = $service->createApplicationPaymentLink(123, false);

        $this->assertNull($result);
    }

    /**
     * @throws Exception
     */
    public function testCreateApplicationPaymentLinkSuccessWithProcessingFee(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => 'price_card_regular',
                'stripe_price_processing' => 'price_proc_regular',
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $mockPaymentLink = new PaymentLink('plink_test123');

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['createPaymentLink'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPaymentLink')
            ->with(
                [
                    ['price' => 'price_card_regular', 'quantity' => 1],
                    ['price' => 'price_proc_regular', 'quantity' => 1],
                ],
                ['application_id' => '456']
            )
            ->willReturn($mockPaymentLink);

        $result = $service->createApplicationPaymentLink(456, false);
        $this->assertSame($mockPaymentLink, $result);
    }

    /**
     * @throws Exception
     */
    public function testCreateApplicationPaymentLinkForEsnerFallback(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => 'price_card_fallback',
                'stripe_price_esncard_esner' => null,
                'stripe_price_processing' => null,
                'stripe_price_processing_esner' => null,
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $mockPaymentLink = new PaymentLink('plink_esner');

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['createPaymentLink'])
            ->getMock();

        $service->expects($this->once())
            ->method('createPaymentLink')
            ->with(
                [
                    ['price' => 'price_card_fallback', 'quantity' => 1],
                ],
                ['application_id' => '789']
            )
            ->willReturn($mockPaymentLink);

        $result = $service->createApplicationPaymentLink('789', true);
        $this->assertSame($mockPaymentLink, $result);
    }

    public function testCreateApplicationWebhookEventThrowsWhenSecretMissing(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_webhook_secret' => null,
            ],
        ]);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with('Stripe Webhook Key not set in the module configuration.');
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $service = new StripeService($configFactory, $loggerFactory);
        $request = new Request();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Stripe Webhook Key not set in the module configuration.');

        $service->createApplicationWebhookEvent($request);
    }

    /**
     * @throws Exception
     */
    public function testCreateApplicationWebhookEventSuccess(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_webhook_secret' => 'whsec_valid_123',
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['createWebhookEvent'])
            ->getMock();

        $request = new Request([], [], [], [], [], [], '{"type": "checkout.session.completed"}');
        $expectedEvent = new Event('evt_123');

        $service->expects($this->once())
            ->method('createWebhookEvent')
            ->with($request, 'whsec_valid_123')
            ->willReturn($expectedEvent);

        $result = $service->createApplicationWebhookEvent($request);
        $this->assertSame($expectedEvent, $result);
    }

    /**
     * @throws Exception
     */
    public function testGetPriceAmountCalculatesCorrectTotal(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => 'price_esn_15',
                'stripe_price_processing' => 'price_fee_1_5',
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['getPrice'])
            ->getMock();

        $esnPrice = new Price('price_esn_15');
        $esnPrice->unit_amount = 1500;

        $procPrice = new Price('price_fee_1_5');
        $procPrice->unit_amount = 150;

        $service->expects($this->exactly(2))
            ->method('getPrice')
            ->willReturnMap([
                ['price_esn_15', $esnPrice],
                ['price_fee_1_5', $procPrice],
            ]);

        $amount = $service->getPriceAmount(false);
        $this->assertEquals(16.5, $amount);
    }

    /**
     * @throws Exception
     */
    public function testGetPriceAmountReturnsNullWhenEsnCardPriceNotFound(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => 'price_missing',
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['getPrice'])
            ->getMock();

        $service->expects($this->once())
            ->method('getPrice')
            ->with('price_missing')
            ->willReturn(null);

        $amount = $service->getPriceAmount(false);
        $this->assertNull($amount);
    }

    /**
     * @throws Exception
     */
    public function testGetPriceAmountReturnsNullWhenProcessingFeeNotFound(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'stripe_price_esncard' => 'price_card_exists',
                'stripe_price_processing' => 'price_proc_missing',
            ],
        ]);
        $loggerFactory = $this->getLoggerFactoryMock();

        $service = $this->getMockBuilder(StripeService::class)
            ->setConstructorArgs([$configFactory, $loggerFactory])
            ->onlyMethods(['getPrice'])
            ->getMock();

        $esnPrice = new Price('price_card_exists');
        $esnPrice->unit_amount = 1000;

        $service->expects($this->exactly(2))
            ->method('getPrice')
            ->willReturnMap([
                ['price_card_exists', $esnPrice],
                ['price_proc_missing', null],
            ]);

        $amount = $service->getPriceAmount(false);
        $this->assertNull($amount);
    }
}
