<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Mail\EmailInterface;

class CardAssignmentEmail extends MembershipEmailBase implements EmailInterface
{
    private string $name;
    private string $cardNumber;
    private ?string $googleWalletLink;
    private ?string $appleWalletLink;

    public function __construct(
        string  $name,
        string  $cardNumber,
        ?string $googleWalletLink = null,
        ?string $appleWalletLink = null
    )
    {
        $this->name = $name;
        $this->cardNumber = $cardNumber;
        $this->googleWalletLink = $googleWalletLink;
        $this->appleWalletLink = $appleWalletLink;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_card_assignment';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        return "Your ESNcard Details";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'name' => $this->name ?? '',
            'esncard_number' => $this->cardNumber ?? '',
            'google_wallet_link' => $this->googleWalletLink ?? '',
            'apple_wallet_link' => $this->appleWalletLink ?? '',
        ]);
    }
}