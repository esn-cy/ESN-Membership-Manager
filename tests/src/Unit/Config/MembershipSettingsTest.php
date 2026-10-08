<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Config;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * @covers \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 */
class MembershipSettingsTest extends MembershipManagerTestCase
{
    public function testConstructorNonEditableUsesGet(): void
    {
        $immutableConfig = $this->createMock(ImmutableConfig::class);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->expects($this->once())
            ->method('get')
            ->with(MembershipSettings::CONFIG_NAME)
            ->willReturn($immutableConfig);
        $configFactory->expects($this->never())
            ->method('getEditable');

        $settings = new MembershipSettings($configFactory, false);
        $this->assertInstanceOf(MembershipSettings::class, $settings);
    }

    public function testConstructorEditableUsesGetEditable(): void
    {
        $mutableConfig = $this->createMock(Config::class);
        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->expects($this->once())
            ->method('getEditable')
            ->with(MembershipSettings::CONFIG_NAME)
            ->willReturn($mutableConfig);
        $configFactory->expects($this->never())
            ->method('get');

        $settings = new MembershipSettings($configFactory, true);
        $this->assertInstanceOf(MembershipSettings::class, $settings);
    }

    public function testGettersReturnDefaultsWhenConfigIsEmpty(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $settings = new MembershipSettings($configFactory);

        $this->assertFalse($settings->getGuestPassSwitch());
        $this->assertFalse($settings->getEstiaSwitch());
        $this->assertFalse($settings->getWeeztixSwitch());
        $this->assertFalse($settings->getGoogleSheetsSwitch());
        $this->assertFalse($settings->getGoogleWalletSwitch());
        $this->assertFalse($settings->getAppleWalletSwitch());
        $this->assertFalse($settings->getDiditSwitch());

        $this->assertEquals('ESN Pass', $settings->getPassName());
        $this->assertNull($settings->getEmailAddress());
        $this->assertNull($settings->getEmailName());
        $this->assertNull($settings->getEmailFooter());
        $this->assertNull($settings->getAdminEmailAddress());

        $this->assertEquals('ESN Guest Pass', $settings->getGuestPassName());
        $this->assertEquals(0, $settings->getGuestPassInstantLimit());
        $this->assertNull($settings->getGuestPassSpecialMobilities());
        $this->assertNull($settings->getGuestPassSpecialInterval());
        $this->assertNull($settings->getGuestPassSpecialPerPersonLimit());
        $this->assertNull($settings->getGuestPassSpecialConcurrentLimit());

        $this->assertNull($settings->getStripeWebhookSecret());
        $this->assertNull($settings->getESNcardPriceID(false));
        $this->assertNull($settings->getESNcardPriceID(true));
        $this->assertNull($settings->getProcessingPriceID(false));
        $this->assertNull($settings->getProcessingPriceID(true));

        $this->assertNull($settings->getWeeztixClientID());
        $this->assertNull($settings->getWeeztixClientSecret());
        $this->assertNull($settings->getWeeztixPassCouponListID());
        $this->assertNull($settings->getWeeztixCardCouponListID());

        $this->assertNull($settings->getSpreadsheetID());
        $this->assertNull($settings->getSheetName());

        $this->assertNull($settings->getAppleCertificateP12());
        $this->assertNull($settings->getAppleCertificatePEM());
        $this->assertNull($settings->getAppleCertificatePassword());
        $this->assertNull($settings->getApplePassTypeID());

        $this->assertNull($settings->getDiditAPIKey());
        $this->assertNull($settings->getDiditWorkflowID());
    }

    public function testGettersReturnConfiguredValues(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'switch_guest_pass' => true,
                'switch_estia' => true,
                'switch_weeztix' => true,
                'switch_google_sheets' => true,
                'switch_google_wallet' => true,
                'switch_apple_wallet' => true,
                'switch_didit' => true,
                'pass_name' => 'Custom Pass',
                'email_address' => 'info@esn.org',
                'email_name' => 'ESN Info',
                'email_footer' => 'Custom Footer Text',
                'admin_email_address' => 'admin@esn.org',
                'guest_pass_name' => 'Custom Guest Pass',
                'guest_pass_instant_limit' => 5,
                'guest_pass_special_mobilities' => ['Erasmus', 'Trainee'],
                'guest_pass_special_interval' => '1 month',
                'guest_pass_special_per_person_limit' => 2,
                'guest_pass_special_concurrent_limit' => 10,
                'stripe_webhook_secret' => 'whsec_12345',
                'stripe_price_esncard' => 'price_card_regular',
                'stripe_price_esncard_esner' => 'price_card_esner',
                'stripe_price_processing' => 'price_proc_regular',
                'stripe_price_processing_esner' => 'price_proc_esner',
                'weeztix_client_id' => 'wz_client_id',
                'weeztix_client_secret' => 'wz_client_secret',
                'weeztix_pass_coupon_list_id' => 'wz_pass_list',
                'weeztix_card_coupon_list_id' => 'wz_card_list',
                'google_spreadsheet_id' => 'sheet_abc_123',
                'google_sheet_name' => '2026_Members',
                'apple_certificate_p12' => 'cert_p12_content',
                'apple_certificate_pem' => 'cert_pem_content',
                'apple_certificate_password' => 'secret_p12_pass',
                'apple_pass_type_id' => 'pass.org.esn.custom',
                'didit_api_key' => 'didit_key_xyz',
                'didit_workflow_id' => 'didit_wf_789',
            ],
        ]);

        $settings = new MembershipSettings($configFactory);

        $this->assertTrue($settings->getGuestPassSwitch());
        $this->assertTrue($settings->getEstiaSwitch());
        $this->assertTrue($settings->getWeeztixSwitch());
        $this->assertTrue($settings->getGoogleSheetsSwitch());
        $this->assertTrue($settings->getGoogleWalletSwitch());
        $this->assertTrue($settings->getAppleWalletSwitch());
        $this->assertTrue($settings->getDiditSwitch());

        $this->assertEquals('Custom Pass', $settings->getPassName());
        $this->assertEquals('info@esn.org', $settings->getEmailAddress());
        $this->assertEquals('ESN Info', $settings->getEmailName());
        $this->assertEquals('Custom Footer Text', $settings->getEmailFooter());
        $this->assertEquals('admin@esn.org', $settings->getAdminEmailAddress());

        $this->assertEquals('Custom Guest Pass', $settings->getGuestPassName());
        $this->assertEquals(5, $settings->getGuestPassInstantLimit());
        $this->assertEquals(['Erasmus', 'Trainee'], $settings->getGuestPassSpecialMobilities());
        $this->assertEquals('1 month', $settings->getGuestPassSpecialInterval());
        $this->assertEquals(2, $settings->getGuestPassSpecialPerPersonLimit());
        $this->assertEquals(10, $settings->getGuestPassSpecialConcurrentLimit());

        $this->assertEquals('whsec_12345', $settings->getStripeWebhookSecret());
        $this->assertEquals('price_card_regular', $settings->getESNcardPriceID(false));
        $this->assertEquals('price_card_esner', $settings->getESNcardPriceID(true));
        $this->assertEquals('price_proc_regular', $settings->getProcessingPriceID(false));
        $this->assertEquals('price_proc_esner', $settings->getProcessingPriceID(true));

        $this->assertEquals('wz_client_id', $settings->getWeeztixClientID());
        $this->assertEquals('wz_client_secret', $settings->getWeeztixClientSecret());
        $this->assertEquals('wz_pass_list', $settings->getWeeztixPassCouponListID());
        $this->assertEquals('wz_card_list', $settings->getWeeztixCardCouponListID());

        $this->assertEquals('sheet_abc_123', $settings->getSpreadsheetID());
        $this->assertEquals('2026_Members', $settings->getSheetName());

        $this->assertEquals('cert_p12_content', $settings->getAppleCertificateP12());
        $this->assertEquals('cert_pem_content', $settings->getAppleCertificatePEM());
        $this->assertEquals('secret_p12_pass', $settings->getAppleCertificatePassword());
        $this->assertEquals('pass.org.esn.custom', $settings->getApplePassTypeID());

        $this->assertEquals('didit_key_xyz', $settings->getDiditAPIKey());
        $this->assertEquals('didit_wf_789', $settings->getDiditWorkflowID());
    }

    public function testSettersAndSave(): void
    {
        $mutableConfig = $this->createMock(Config::class);

        $setExpected = [
            ['switch_guest_pass', true],
            ['switch_estia', true],
            ['switch_weeztix', true],
            ['switch_google_sheets', true],
            ['switch_google_wallet', true],
            ['switch_apple_wallet', true],
            ['switch_didit', true],
            ['pass_name', 'New Pass Name'],
            ['email_address', 'new@esn.org'],
            ['email_name', 'New Name'],
            ['email_footer', 'New Footer'],
            ['admin_email_address', 'new_admin@esn.org'],
            ['guest_pass_name', 'New Guest Pass'],
            ['guest_pass_instant_limit', 15],
            ['guest_pass_special_mobilities', ['Student']],
            ['guest_pass_special_interval', '2 weeks'],
            ['guest_pass_special_per_person_limit', 3],
            ['guest_pass_special_concurrent_limit', 8],
            ['stripe_webhook_secret', 'whsec_new'],
            ['stripe_price_esncard', 'price_reg'],
            ['stripe_price_esncard_esner', 'price_esner'],
            ['stripe_price_processing', 'proc_reg'],
            ['stripe_price_processing_esner', 'proc_esner'],
            ['weeztix_client_id', 'cid_new'],
            ['weeztix_client_secret', 'csec_new'],
            ['weeztix_pass_coupon_list_id', 'wz_pass_new'],
            ['weeztix_card_coupon_list_id', 'wz_card_new'],
            ['google_spreadsheet_id', 'gsheet_new'],
            ['google_sheet_name', 'Sheet1'],
            ['apple_certificate_p12', 'p12_new'],
            ['apple_certificate_pem', 'pem_new'],
            ['apple_certificate_password', 'pass_new'],
            ['apple_pass_type_id', 'type_new'],
            ['didit_api_key', 'dkey_new'],
            ['didit_workflow_id', 'dwf_new'],
        ];

        $mutableConfig->expects($this->exactly(count($setExpected)))
            ->method('set')
            ->willReturnCallback(function ($key, $value) use (&$setExpected, $mutableConfig) {
                $expected = array_shift($setExpected);
                $this->assertEquals($expected[0], $key);
                $this->assertEquals($expected[1], $value);
                return $mutableConfig;
            });

        $mutableConfig->expects($this->once())
            ->method('save');

        $configFactory = $this->createMock(ConfigFactoryInterface::class);
        $configFactory->method('getEditable')
            ->with(MembershipSettings::CONFIG_NAME)
            ->willReturn($mutableConfig);

        $settings = new MembershipSettings($configFactory, true);

        $this->assertSame($settings, $settings->setGuestPassSwitch(true));
        $this->assertSame($settings, $settings->setEstiaSwitch(true));
        $this->assertSame($settings, $settings->setWeeztixSwitch(true));
        $this->assertSame($settings, $settings->setGoogleSheetsSwitch(true));
        $this->assertSame($settings, $settings->setGoogleWalletSwitch(true));
        $this->assertSame($settings, $settings->setAppleWalletSwitch(true));
        $this->assertSame($settings, $settings->setDiditSwitch(true));

        $this->assertSame($settings, $settings->setPassName('New Pass Name'));
        $this->assertSame($settings, $settings->setEmailAddress('new@esn.org'));
        $this->assertSame($settings, $settings->setEmailName('New Name'));
        $this->assertSame($settings, $settings->setEmailFooter('New Footer'));
        $this->assertSame($settings, $settings->setAdminEmailAddress('new_admin@esn.org'));

        $this->assertSame($settings, $settings->setGuestPassName('New Guest Pass'));
        $this->assertSame($settings, $settings->setGuestPassInstantLimit(15));
        $this->assertSame($settings, $settings->setGuestPassSpecialMobilities(['Student']));
        $this->assertSame($settings, $settings->setGuestPassSpecialInterval('2 weeks'));
        $this->assertSame($settings, $settings->setGuestPassSpecialPerPersonLimit(3));
        $this->assertSame($settings, $settings->setGuestPassSpecialConcurrentLimit(8));

        $this->assertSame($settings, $settings->setStripeWebhookSecret('whsec_new'));
        $this->assertSame($settings, $settings->setESNcardPriceID('price_reg', false));
        $this->assertSame($settings, $settings->setESNcardPriceID('price_esner', true));
        $this->assertSame($settings, $settings->setProcessingPriceID('proc_reg', false));
        $this->assertSame($settings, $settings->setProcessingPriceID('proc_esner', true));

        $this->assertSame($settings, $settings->setWeeztixClientID('cid_new'));
        $this->assertSame($settings, $settings->setWeeztixClientSecret('csec_new'));
        $this->assertSame($settings, $settings->setWeeztixPassCouponListID('wz_pass_new'));
        $this->assertSame($settings, $settings->setWeeztixCardCouponListID('wz_card_new'));

        $this->assertSame($settings, $settings->setSpreadsheetID('gsheet_new'));
        $this->assertSame($settings, $settings->setSheetName('Sheet1'));

        $this->assertSame($settings, $settings->setAppleCertificateP12('p12_new'));
        $this->assertSame($settings, $settings->setAppleCertificatePEM('pem_new'));
        $this->assertSame($settings, $settings->setAppleCertificatePassword('pass_new'));
        $this->assertSame($settings, $settings->setApplePassTypeID('type_new'));

        $this->assertSame($settings, $settings->setDiditAPIKey('dkey_new'));
        $this->assertSame($settings, $settings->setDiditWorkflowID('dwf_new'));

        $settings->save();
    }
}
