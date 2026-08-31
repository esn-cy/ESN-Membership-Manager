<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Mail\EmailInterface;

class CardIssuanceEmail extends MembershipEmailBase implements EmailInterface
{
    private string $name;

    public function __construct(
        string $name,
    )
    {
        $this->name = $name;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_card_issuance';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        return "ESNcard Issued";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'name' => $this->name ?? '',
        ]);
    }
}