<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Mail\EmailInterface;

class RejectionEmail extends MembershipEmailBase implements EmailInterface
{
    private string $name;
    private ?array $reasons;

    public function __construct(
        string $name,
        ?array $reasons = null,
    )
    {
        $this->name = $name;
        $this->reasons = $reasons;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_rejection';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        return "Application Rejected";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'name' => $this->name ?? '',
            'reasons' => $this->reasons ?? [],
        ]);
    }
}