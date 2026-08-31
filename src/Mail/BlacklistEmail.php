<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Mail\EmailInterface;

class BlacklistEmail extends MembershipEmailBase implements EmailInterface
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
        return 'emm_blacklist';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        return "Application Blacklisted";
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