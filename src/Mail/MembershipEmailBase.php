<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Mail\EmailBase;
use Drupal\omnia\Mail\EmailInterface;

abstract class MembershipEmailBase extends EmailBase implements EmailInterface
{
    /**
     * {@inheritDoc}
     */
    public static function getModuleName(): string
    {
        return 'esn_membership_manager';
    }

    /**
     * {@inheritDoc}
     */
    public function getFromEmail(): ?string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);
        return $membershipSettings->getEmailAddress();
    }

    /**
     * {@inheritDoc}
     */
    public function getFromName(): ?string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);
        return $membershipSettings->getEmailName();
    }

    /**
     * {@inheritDoc}
     */
    public function getVariables(): array
    {
        $settings = isset($this->configFactory) ? new MembershipSettings($this->configFactory) : null;

        return array_merge(parent::getVariables(), [
            'name' => $settings ? 'Student' : '',
            'scheme_name' => $settings ? ($settings->getPassName() ?? 'ESN Pass') : '',
            'guest_scheme_name' => $settings ? ($settings->getGuestPassName() ?? 'ESN Guest Pass') : '',
            'dashboard_url' => $settings ? Url::fromRoute('esn_membership_manager.dashboard', [], ['absolute' => true]) : '',
            'footer' => $settings ? ($settings->getEmailFooter() ?? parent::getVariables()['footer']) : '',
        ]);
    }
}