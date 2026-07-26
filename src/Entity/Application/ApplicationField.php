<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal\omnia\Entity\FieldEnumInterface;

enum ApplicationField: string implements FieldEnumInterface
{
    case Name = 'name';
    case Surname = 'surname';
    case Email = 'email';
    case Nationality = 'nationality';
    case DateOfBirth = 'dob';
    case MobilityStatus = 'mobility_status';
    case Section = 'section';
    case HostInstitution = 'host_institution';
    case StatusProofFileID = 'proof_fid';
    case IdentityDocumentFileID = 'id_document_fid';
    case FacePhotoFileID = 'face_photo_fid';
    case HasVerifiedEmail = 'verified_email';
    case HasVerifiedID = 'verified_id';
    case HasVerifiedStatus = 'verified_status';
    case HasESNcard = 'esncard';
    case PassToken = 'pass_token';
    case ESNcardNumber = 'esncard_number';
    case PaymentLink = 'payment_link';
    case PaymentLinkID = 'payment_link_id';
    case ApprovalStatus = 'approval_status';
    case DateCreated = 'date_created';
    case DateApproved = 'date_approved';
    case DatePaid = 'date_paid';
    case DateLastScanned = 'date_last_scanned';
    case DateLastModified = 'date_last_modified';

    public function label(): string
    {
        return match ($this) {
            self::Name => 'First Name',
            self::Surname => 'Last Name',
            self::Email => 'Email',
            self::Nationality => 'Nationality',
            self::DateOfBirth => 'Date Of Birth',
            self::Section => 'Section',
            self::MobilityStatus => 'Mobility Status',
            self::HostInstitution => 'Host Institution',
            self::StatusProofFileID => 'Proof of Mobility',
            self::IdentityDocumentFileID => 'ID Document',
            self::FacePhotoFileID => 'Face Photo',
            self::HasVerifiedEmail => 'Verified Email',
            self::HasVerifiedID => 'Verified ID',
            self::HasVerifiedStatus => 'Verified Status',
            self::HasESNcard => 'ESNcard',
            self::PassToken => 'Pass Token',
            self::ESNcardNumber => 'ESNcard Number',
            self::PaymentLink => 'Payment Link',
            self::PaymentLinkID => 'Payment Link ID',
            self::ApprovalStatus => 'Approval Status',
            self::DateCreated => 'Date Created',
            self::DateApproved => 'Date Approved',
            self::DatePaid => 'Date Paid',
            self::DateLastScanned => 'Date Last Scanned',
            self::DateLastModified => 'Date Last Modified',
        };
    }

    public function type(): string
    {
        return match ($this) {
            self::Name, self::Surname, self::Nationality, self::DateOfBirth, self::Section, self::MobilityStatus, self::HostInstitution, self::PassToken, self::ESNcardNumber, self::PaymentLink, self::PaymentLinkID, self::ApprovalStatus => 'string',
            self::Email => 'email',
            self::StatusProofFileID, self::IdentityDocumentFileID, self::FacePhotoFileID => 'entity_reference',
            self::HasVerifiedEmail, self::HasVerifiedID, self::HasVerifiedStatus, self::HasESNcard => 'boolean',
            self::DateCreated, self::DateApproved, self::DatePaid, self::DateLastScanned, self::DateLastModified => 'datetime',
        };
    }

    public function required(): bool
    {
        return match ($this) {
            self::Name, self::Surname, self::Email, self::Nationality, self::DateOfBirth, self::Section, self::MobilityStatus, self::HostInstitution, self::HasESNcard, self::ApprovalStatus, self::HasVerifiedEmail, self::HasVerifiedID, self::HasVerifiedStatus, self::DateCreated => true,
            default => false,
        };
    }

    public function unique(): bool
    {
        return match ($this) {
            self::Email, self::ESNcardNumber, self::PassToken => true,
            default => false,
        };
    }

    public function unlimitedCardinality(): bool
    {
        return false;
    }

    public function isReadOnly(?bool $verifiedEmail = false, ?bool $verifiedID = false, ?bool $verifiedStatus = false): bool
    {
        return match ($this) {
            self::StatusProofFileID, self::IdentityDocumentFileID, self::FacePhotoFileID, self::HasESNcard, self::PaymentLink, self::PaymentLinkID, self::ApprovalStatus, self::HasVerifiedEmail, self::HasVerifiedID, self::HasVerifiedStatus, self::DateCreated, self::DateApproved, self::DatePaid, self::DateLastModified => true,
            self::Email => $verifiedEmail,
            self::Name, self::Surname, self::Nationality, self::DateOfBirth => $verifiedID,
            self::MobilityStatus, self::HostInstitution => $verifiedStatus,
            default => false,
        };
    }

    public function isESNcardExclusive(): bool
    {
        return match ($this) {
            self::FacePhotoFileID, self::ESNcardNumber, self::PaymentLink, self::PaymentLinkID, self::DatePaid => true,
            default => false,
        };
    }

    public function permissions(): array
    {
        return match ($this) {
            self::ApprovalStatus => ['approve applications', 'reject applications', 'mark applications as paid', 'blacklist applications', 'issue cards', 'deliver cards'],
            self::PassToken, self::PaymentLink, self::PaymentLinkID, self::DateApproved => ['approve applications'],
            self::DateLastScanned => ['scan cards'],
            self::ESNcardNumber, self::DatePaid => ['mark applications as paid'],
            default => ['edit applications'],
        };
    }

    public function default(): mixed
    {
        return match ($this) {
            self::HasESNcard, self::HasVerifiedEmail, self::HasVerifiedID, self::HasVerifiedStatus => 0,
            self::ApprovalStatus => 'Pending',
            default => null,
        };
    }

    public function settings(): array
    {
        return match ($this) {
            self::Name, self::Surname, self::Email, self::HostInstitution, self::PaymentLink, self::PaymentLinkID, self::ApprovalStatus => ['max_length' => 255],
            self::Nationality => ['max_length' => 128],
            self::MobilityStatus, self::PassToken, self::ESNcardNumber, self::DateCreated, self::DateApproved, self::DatePaid, self::DateLastScanned, self::DateLastModified => ['max_length' => 64],
            self::DateOfBirth => ['max_length' => 20],
            self::StatusProofFileID, self::IdentityDocumentFileID, self::FacePhotoFileID => ['target_type' => 'file'],
            default => [],
        };
    }
}