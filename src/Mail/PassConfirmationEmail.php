<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Mail\EmailInterface;

class PassConfirmationEmail extends MembershipEmailBase implements EmailInterface
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
        return 'emm_pass_confirmation';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $schemeName = $membershipSettings->getPassName() ?? 'ESN Pass';

        return "$schemeName Application Received";
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