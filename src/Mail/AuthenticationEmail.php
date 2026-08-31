<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Config\OmniaSettings;
use Drupal\omnia\Mail\EmailInterface;

class AuthenticationEmail extends MembershipEmailBase implements EmailInterface
{
    private string $type;
    private string $code;

    public function __construct(
        string $type,
        string $code
    )
    {
        $this->type = $type;
        $this->code = $code;
    }

    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_authentication';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        $omniaSettings = new OmniaSettings($this->configFactory);

        $organisationName = $omniaSettings->getOrganisationName() ?? 'ESN';

        return "$organisationName Authentication Code";
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        return array_merge(parent::getVariables(), [
            'authentication_type' => $this->type ?? '',
            'authentication_code' => $this->code ?? ''
        ]);
    }
}