<?php

namespace Drupal\esn_membership_manager\Mail;

use Drupal\omnia\Mail\EmailInterface;

class BacklogEmail extends MembershipEmailBase implements EmailInterface
{
    /**
     * {@inheritDoc}
     */
    public static function getKey(): string
    {
        return 'emm_admin_backlogged';
    }

    /**
     * {@inheritDoc}
     */
    public function getSubject(): string
    {
        return "ESNcard Backlogged";
    }
}