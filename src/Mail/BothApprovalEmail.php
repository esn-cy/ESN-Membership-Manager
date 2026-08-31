<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Mail\EmailInterface;

class BothApprovalEmail extends MembershipEmailBase implements EmailInterface
{
    private string $name;
    private string $passToken;
    private string $paymentLink;
    private ?string $googleWalletLink;
    private ?string $appleWalletLink;

    public function __construct(
        string  $name,
        string  $passToken,
        string  $paymentLink,
        ?string $googleWalletLink = null,
        ?string $appleWalletLink = null
    )
    {
        $this->name = $name;
        $this->passToken = $passToken;
        $this->paymentLink = $paymentLink;
        $this->googleWalletLink = $googleWalletLink;
        $this->appleWalletLink = $appleWalletLink;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_both_approval';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $schemeName = $membershipSettings->getPassName() ?? 'ESN Pass';

        return "$schemeName and ESNcard Approval";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'name' => $this->name ?? '',
            'pass_token' => $this->passToken ?? '',
            'payment_link' => $this->paymentLink ?? '',
            'google_wallet_link' => $this->googleWalletLink ?? '',
            'apple_wallet_link' => $this->appleWalletLink ?? '',
        ]);
    }
}