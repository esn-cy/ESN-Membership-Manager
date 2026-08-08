<?php

namespace Drupal\esn_membership_manager\Config;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;

class MembershipSettings
{
    public const CONFIG_NAME = 'esn_membership_manager.settings';

    private Config|ImmutableConfig $config;

    public function __construct(
        ConfigFactoryInterface $configFactory,
        bool                   $editable = false
    )
    {
        if ($editable) {
            $this->config = $configFactory->getEditable(self::CONFIG_NAME);
        } else {
            $this->config = $configFactory->get(self::CONFIG_NAME);
        }
    }

    public function getGuestPassSwitch(): bool
    {
        return $this->config->get('switch_guest_pass') ?? false;
    }

    public function setGuestPassSwitch(bool $value): self
    {
        $this->config->set('switch_guest_pass', $value);
        return $this;
    }

    public function getEstiaSwitch(): bool
    {
        return $this->config->get('switch_estia') ?? false;
    }

    public function setEstiaSwitch(bool $value): self
    {
        $this->config->set('switch_estia', $value);
        return $this;
    }

    public function getWeeztixSwitch(): bool
    {
        return $this->config->get('switch_weeztix') ?? false;
    }

    public function setWeeztixSwitch(bool $value): self
    {
        $this->config->set('switch_weeztix', $value);
        return $this;
    }

    public function getGoogleSheetsSwitch(): bool
    {
        return $this->config->get('switch_google_sheets') ?? false;
    }

    public function setGoogleSheetsSwitch(bool $value): self
    {
        $this->config->set('switch_google_sheets', $value);
        return $this;
    }

    public function getGoogleWalletSwitch(): bool
    {
        return $this->config->get('switch_google_wallet') ?? false;
    }

    public function setGoogleWalletSwitch(bool $value): self
    {
        $this->config->set('switch_google_wallet', $value);
        return $this;
    }

    public function getAppleWalletSwitch(): bool
    {
        return $this->config->get('switch_apple_wallet') ?? false;
    }

    public function setAppleWalletSwitch(bool $value): self
    {
        $this->config->set('switch_apple_wallet', $value);
        return $this;
    }

    public function getDiditSwitch(): bool
    {
        return $this->config->get('switch_didit') ?? false;
    }

    public function setDiditSwitch(bool $value): self
    {
        $this->config->set('switch_didit', $value);
        return $this;
    }

    public function getPassName(): string
    {
        return $this->config->get('pass_name') ?? 'ESN Pass';
    }

    public function setPassName(?string $value): self
    {
        $this->config->set('pass_name', $value);
        return $this;
    }

    public function getEmailAddress(): ?string
    {
        return $this->config->get('email_address') ?? null;
    }

    public function setEmailAddress(string $value): self
    {
        $this->config->set('email_address', $value);
        return $this;
    }

    public function getEmailName(): ?string
    {
        return $this->config->get('email_name') ?? null;
    }

    public function setEmailName(string $value): self
    {
        $this->config->set('email_name', $value);
        return $this;
    }

    public function getEmailFooter(): ?string
    {
        return $this->config->get('email_footer') ?? null;
    }

    public function setEmailFooter(string $value): self
    {
        $this->config->set('email_footer', $value);
        return $this;
    }

    public function getAdminEmailAddress(): ?string
    {
        return $this->config->get('admin_email_address');
    }

    public function setAdminEmailAddress(string $value): self
    {
        $this->config->set('admin_email_address', $value);
        return $this;
    }

    public function getGuestPassName(): string
    {
        return $this->config->get('guest_pass_name') ?? 'ESN Guest Pass';
    }

    public function setGuestPassName(?string $value): self
    {
        $this->config->set('guest_pass_name', $value);
        return $this;
    }

    public function getGuestPassInstantLimit(): int
    {
        return $this->config->get('guest_pass_instant_limit') ?? 0;
    }

    public function setGuestPassInstantLimit(int $value): self
    {
        $this->config->set('guest_pass_instant_limit', $value);
        return $this;
    }

    public function getGuestPassSpecialMobilities(): ?array
    {
        return $this->config->get('guest_pass_special_mobilities');
    }

    public function setGuestPassSpecialMobilities(array $value): self
    {
        $this->config->set('guest_pass_special_mobilities', $value);
        return $this;
    }

    public function getGuestPassSpecialInterval(): ?string
    {
        return $this->config->get('guest_pass_special_interval');
    }

    public function setGuestPassSpecialInterval(string $value): self
    {
        $this->config->set('guest_pass_special_interval', $value);
        return $this;
    }

    public function getGuestPassSpecialPerPersonLimit(): ?int
    {
        return $this->config->get('guest_pass_special_per_person_limit');
    }

    public function setGuestPassSpecialPerPersonLimit(int $value): self
    {
        $this->config->set('guest_pass_special_per_person_limit', $value);
        return $this;
    }

    public function getGuestPassSpecialConcurrentLimit(): ?int
    {
        return $this->config->get('guest_pass_special_concurrent_limit');
    }

    public function setGuestPassSpecialConcurrentLimit(int $value): self
    {
        $this->config->set('guest_pass_special_concurrent_limit', $value);
        return $this;
    }

    public function getStripeWebhookSecret(): ?string
    {
        return $this->config->get('stripe_webhook_secret');
    }

    public function setStripeWebhookSecret(string $value): self
    {
        $this->config->set('stripe_webhook_secret', $value);
        return $this;
    }

    public function getESNcardPriceID(bool $isESNer): ?string
    {
        if ($isESNer) {
            return $this->config->get('stripe_price_esncard_esner');
        } else {
            return $this->config->get('stripe_price_esncard');
        }
    }

    public function setESNcardPriceID(string $value, bool $isESNer): self
    {
        if ($isESNer) {
            $this->config->set('stripe_price_esncard_esner', $value);
        } else {
            $this->config->set('stripe_price_esncard', $value);
        }
        return $this;
    }

    public function getProcessingPriceID(bool $isESNer): ?string
    {
        if ($isESNer) {
            return $this->config->get('stripe_price_processing_esner');
        } else {
            return $this->config->get('stripe_price_processing');
        }
    }

    public function setProcessingPriceID(string $value, bool $isESNer): self
    {
        if ($isESNer) {
            $this->config->set('stripe_price_processing_esner', $value);
        } else {
            $this->config->set('stripe_price_processing', $value);
        }
        return $this;
    }

    public function getWeeztixClientID(): ?string
    {
        return $this->config->get('weeztix_client_id');
    }

    public function setWeeztixClientID(string $value): self
    {
        $this->config->set('weeztix_client_id', $value);
        return $this;
    }

    public function getWeeztixClientSecret(): ?string
    {
        return $this->config->get('weeztix_client_secret');
    }

    public function setWeeztixClientSecret(string $value): self
    {
        $this->config->set('weeztix_client_secret', $value);
        return $this;
    }

    public function getWeeztixPassCouponListID(): ?string
    {
        return $this->config->get('weeztix_pass_coupon_list_id');
    }

    public function setWeeztixPassCouponListID(string $value): self
    {
        $this->config->set('weeztix_pass_coupon_list_id', $value);
        return $this;
    }

    public function getWeeztixCardCouponListID(): ?string
    {
        return $this->config->get('weeztix_card_coupon_list_id');
    }

    public function setWeeztixCardCouponListID(string $value): self
    {
        $this->config->set('weeztix_card_coupon_list_id', $value);
        return $this;
    }

    public function getSpreadsheetID(): ?string
    {
        return $this->config->get('google_spreadsheet_id');
    }

    public function setSpreadsheetID(string $value): self
    {
        $this->config->set('google_spreadsheet_id', $value);
        return $this;
    }

    public function getSheetName(): ?string
    {
        return $this->config->get('google_sheet_name');
    }

    public function setSheetName(string $value): self
    {
        $this->config->set('google_sheet_name', $value);
        return $this;
    }

    public function getAppleCertificateP12(): ?string
    {
        return $this->config->get('apple_certificate_p12');
    }

    public function setAppleCertificateP12(string $value): self
    {
        $this->config->set('apple_certificate_p12', $value);
        return $this;
    }

    public function getAppleCertificatePEM(): ?string
    {
        return $this->config->get('apple_certificate_pem');
    }

    public function setAppleCertificatePEM(string $value): self
    {
        $this->config->set('apple_certificate_pem', $value);
        return $this;
    }

    public function getAppleCertificatePassword(): ?string
    {
        return $this->config->get('apple_certificate_password');
    }

    public function setAppleCertificatePassword(string $value): self
    {
        $this->config->set('apple_certificate_password', $value);
        return $this;
    }

    public function getApplePassTypeID(): ?string
    {
        return $this->config->get('apple_pass_type_id');
    }

    public function setApplePassTypeID(string $value): self
    {
        $this->config->set('apple_pass_type_id', $value);
        return $this;
    }

    public function getDiditAPIKey(): ?string
    {
        return $this->config->get('didit_api_key');
    }

    public function setDiditAPIKey(string $value): self
    {
        $this->config->set('didit_api_key', $value);
        return $this;
    }

    public function getDiditWorkflowID(): ?string
    {
        return $this->config->get('didit_workflow_id');
    }

    public function setDiditWorkflowID(string $value): self
    {
        $this->config->set('didit_workflow_id', $value);
        return $this;
    }

    public function save(): void
    {
        $this->config->save();
    }
}
