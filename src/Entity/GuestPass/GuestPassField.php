<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use Drupal\esn_membership_manager\Entity\FieldEnumInterface;

enum GuestPassField: string implements FieldEnumInterface
{
    case RefererID = 'referer_id';
    case Name = 'name';
    case Surname = 'surname';
    case Email = 'email';
    case PassToken = 'guest_pass_token';
    case DateCreated = 'date_created';
    case DateApproved = 'date_approved';
    case DateRedeemed = 'date_redeemed';
    case DateLastModified = 'date_last_modified';

    public function label(): string
    {
        return match ($this) {
            self::RefererID => 'Referer ID',
            self::Name => 'First Name',
            self::Surname => 'Last Name',
            self::Email => 'Email',
            self::PassToken => 'Pass Token',
            self::DateCreated => 'Date Created',
            self::DateApproved => 'Date Approved',
            self::DateRedeemed => 'Date Redeemed',
            self::DateLastModified => 'Date Last Modified',
        };
    }

    public function type(): string
    {
        return match ($this) {
            self::RefererID => 'entity_reference',
            self::Name, self::Surname, self::PassToken => 'string',
            self::Email => 'email',
            self::DateCreated, self::DateApproved, self::DateRedeemed, self::DateLastModified => 'datetime',
        };
    }

    public function required(): bool
    {
        return match ($this) {
            self::RefererID, self::Name, self::Surname, self::Email, self::DateCreated => true,
            default => false,
        };
    }

    public function unique(): bool
    {
        return match ($this) {
            self::PassToken => true,
            default => false,
        };
    }

    public function default(): null
    {
        return null;
    }

    public function settings(): array
    {
        return match ($this) {
            self::Name, self::Surname, self::Email => ['max_length' => 255],
            self::PassToken, self::DateCreated, self::DateApproved, self::DateRedeemed, self::DateLastModified => ['max_length' => 64],
            self::RefererID => ['target_type' => 'membership_application'],
        };
    }
}