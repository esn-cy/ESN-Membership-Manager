<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Mail\EmailInterface;

class GuestPassApprovalEmail extends MembershipEmailBase implements EmailInterface
{
    private string $name;
    private string $passToken;
    private ?string $googleWalletLink;
    private ?string $appleWalletLink;

    public function __construct(
        string  $name,
        string  $passToken,
        ?string $googleWalletLink = null,
        ?string $appleWalletLink = null
    )
    {
        $this->name = $name;
        $this->passToken = $passToken;
        $this->googleWalletLink = $googleWalletLink;
        $this->appleWalletLink = $appleWalletLink;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_guest_approval';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $schemeName = $membershipSettings->getGuestPassName() ?? 'ESN Guest Pass';

        return "$schemeName Approved";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'name' => $this->name ?? '',
            'pass_token' => $this->passToken ?? '',
            'google_wallet_link' => $this->googleWalletLink ?? '',
            'apple_wallet_link' => $this->appleWalletLink ?? '',
        ]);
    }
}