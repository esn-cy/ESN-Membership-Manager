<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\Application;

use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * Unit tests for ApplicationField enum.
 *
 * @covers \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @group esn_membership_manager
 */
class ApplicationFieldTest extends MembershipManagerTestCase
{
    public function testCasesCount(): void
    {
        $this->assertCount(25, ApplicationField::cases());
    }

    public function testLabels(): void
    {
        foreach (ApplicationField::cases() as $field) {
            $label = $field->label();
            $this->assertNotEmpty($label);
            $this->assertIsString($label);
        }

        $this->assertEquals('First Name', ApplicationField::Name->label());
        $this->assertEquals('Last Name', ApplicationField::Surname->label());
        $this->assertEquals('Email', ApplicationField::Email->label());
        $this->assertEquals('Nationality', ApplicationField::Nationality->label());
        $this->assertEquals('Date Of Birth', ApplicationField::DateOfBirth->label());
        $this->assertEquals('Section', ApplicationField::Section->label());
        $this->assertEquals('Mobility Status', ApplicationField::MobilityStatus->label());
        $this->assertEquals('Host Institution', ApplicationField::HostInstitution->label());
        $this->assertEquals('Proof of Mobility', ApplicationField::StatusProofFileID->label());
        $this->assertEquals('ID Document', ApplicationField::IdentityDocumentFileID->label());
        $this->assertEquals('Face Photo', ApplicationField::FacePhotoFileID->label());
        $this->assertEquals('Verified Email', ApplicationField::HasVerifiedEmail->label());
        $this->assertEquals('Verified ID', ApplicationField::HasVerifiedID->label());
        $this->assertEquals('Verified Status', ApplicationField::HasVerifiedStatus->label());
        $this->assertEquals('ESNcard', ApplicationField::HasESNcard->label());
        $this->assertEquals('Pass Token', ApplicationField::PassToken->label());
        $this->assertEquals('ESNcard Number', ApplicationField::ESNcardNumber->label());
        $this->assertEquals('Payment Link', ApplicationField::PaymentLink->label());
        $this->assertEquals('Payment Link ID', ApplicationField::PaymentLinkID->label());
        $this->assertEquals('Approval Status', ApplicationField::ApprovalStatus->label());
        $this->assertEquals('Date Created', ApplicationField::DateCreated->label());
        $this->assertEquals('Date Approved', ApplicationField::DateApproved->label());
        $this->assertEquals('Date Paid', ApplicationField::DatePaid->label());
        $this->assertEquals('Date Last Scanned', ApplicationField::DateLastScanned->label());
        $this->assertEquals('Date Last Modified', ApplicationField::DateLastModified->label());
    }

    public function testTypes(): void
    {
        $this->assertEquals('string', ApplicationField::Name->type());
        $this->assertEquals('string', ApplicationField::Surname->type());
        $this->assertEquals('string', ApplicationField::Nationality->type());
        $this->assertEquals('string', ApplicationField::DateOfBirth->type());
        $this->assertEquals('string', ApplicationField::Section->type());
        $this->assertEquals('string', ApplicationField::MobilityStatus->type());
        $this->assertEquals('string', ApplicationField::HostInstitution->type());
        $this->assertEquals('string', ApplicationField::PassToken->type());
        $this->assertEquals('string', ApplicationField::ESNcardNumber->type());
        $this->assertEquals('string', ApplicationField::PaymentLink->type());
        $this->assertEquals('string', ApplicationField::PaymentLinkID->type());
        $this->assertEquals('string', ApplicationField::ApprovalStatus->type());

        $this->assertEquals('email', ApplicationField::Email->type());

        $this->assertEquals('entity_reference', ApplicationField::StatusProofFileID->type());
        $this->assertEquals('entity_reference', ApplicationField::IdentityDocumentFileID->type());
        $this->assertEquals('entity_reference', ApplicationField::FacePhotoFileID->type());

        $this->assertEquals('boolean', ApplicationField::HasVerifiedEmail->type());
        $this->assertEquals('boolean', ApplicationField::HasVerifiedID->type());
        $this->assertEquals('boolean', ApplicationField::HasVerifiedStatus->type());
        $this->assertEquals('boolean', ApplicationField::HasESNcard->type());

        $this->assertEquals('datetime', ApplicationField::DateCreated->type());
        $this->assertEquals('datetime', ApplicationField::DateApproved->type());
        $this->assertEquals('datetime', ApplicationField::DatePaid->type());
        $this->assertEquals('datetime', ApplicationField::DateLastScanned->type());
        $this->assertEquals('datetime', ApplicationField::DateLastModified->type());
    }

    public function testRequired(): void
    {
        $requiredFields = [
            ApplicationField::Name,
            ApplicationField::Surname,
            ApplicationField::Email,
            ApplicationField::Nationality,
            ApplicationField::DateOfBirth,
            ApplicationField::Section,
            ApplicationField::MobilityStatus,
            ApplicationField::HostInstitution,
            ApplicationField::HasESNcard,
            ApplicationField::ApprovalStatus,
            ApplicationField::HasVerifiedEmail,
            ApplicationField::HasVerifiedID,
            ApplicationField::HasVerifiedStatus,
            ApplicationField::DateCreated,
        ];

        foreach (ApplicationField::cases() as $field) {
            if (in_array($field, $requiredFields, true)) {
                $this->assertTrue($field->required(), "Field $field->name should be required.");
            } else {
                $this->assertFalse($field->required(), "Field $field->name should not be required.");
            }
        }
    }

    public function testUnique(): void
    {
        $uniqueFields = [
            ApplicationField::Email,
            ApplicationField::ESNcardNumber,
            ApplicationField::PassToken,
        ];

        foreach (ApplicationField::cases() as $field) {
            if (in_array($field, $uniqueFields, true)) {
                $this->assertTrue($field->unique(), "Field $field->name should be unique.");
            } else {
                $this->assertFalse($field->unique(), "Field $field->name should not be unique.");
            }
        }
    }

    public function testUnlimitedCardinality(): void
    {
        foreach (ApplicationField::cases() as $field) {
            $this->assertFalse($field->unlimitedCardinality(), "Field $field->name should not have unlimited cardinality.");
        }
    }

    public function testIsReadOnly(): void
    {
        // Always read-only fields
        $alwaysReadOnly = [
            ApplicationField::StatusProofFileID,
            ApplicationField::IdentityDocumentFileID,
            ApplicationField::FacePhotoFileID,
            ApplicationField::HasESNcard,
            ApplicationField::PaymentLink,
            ApplicationField::PaymentLinkID,
            ApplicationField::ApprovalStatus,
            ApplicationField::HasVerifiedEmail,
            ApplicationField::HasVerifiedID,
            ApplicationField::HasVerifiedStatus,
            ApplicationField::DateCreated,
            ApplicationField::DateApproved,
            ApplicationField::DatePaid,
            ApplicationField::DateLastModified,
        ];

        foreach ($alwaysReadOnly as $field) {
            $this->assertTrue($field->isReadOnly(false, false, false), "Field $field->name should always be read-only.");
        }

        // Email depends on verifiedEmail
        $this->assertTrue(ApplicationField::Email->isReadOnly(true));
        $this->assertFalse(ApplicationField::Email->isReadOnly());

        // Name, Surname, Nationality, DateOfBirth depend on verifiedID
        $idFields = [
            ApplicationField::Name,
            ApplicationField::Surname,
            ApplicationField::Nationality,
            ApplicationField::DateOfBirth,
        ];
        foreach ($idFields as $field) {
            $this->assertTrue($field->isReadOnly(false, true, false), "Field $field->name should be read-only when verifiedID is true.");
            $this->assertFalse($field->isReadOnly(false, false, false), "Field $field->name should not be read-only when verifiedID is false.");
        }

        // MobilityStatus, HostInstitution depend on verifiedStatus
        $statusFields = [
            ApplicationField::MobilityStatus,
            ApplicationField::HostInstitution,
        ];
        foreach ($statusFields as $field) {
            $this->assertTrue($field->isReadOnly(false, false, true), "Field $field->name should be read-only when verifiedStatus is true.");
            $this->assertFalse($field->isReadOnly(false, false, false), "Field $field->name should not be read-only when verifiedStatus is false.");
        }

        // Default fields are never read-only
        $defaultFields = [
            ApplicationField::Section,
            ApplicationField::PassToken,
            ApplicationField::ESNcardNumber,
            ApplicationField::DateLastScanned,
        ];
        foreach ($defaultFields as $field) {
            $this->assertFalse($field->isReadOnly(true, true, true), "Field $field->name should not be read-only.");
        }
    }

    public function testIsESNcardExclusive(): void
    {
        $exclusive = [
            ApplicationField::FacePhotoFileID,
            ApplicationField::ESNcardNumber,
            ApplicationField::PaymentLink,
            ApplicationField::PaymentLinkID,
            ApplicationField::DatePaid,
        ];

        foreach (ApplicationField::cases() as $field) {
            if (in_array($field, $exclusive, true)) {
                $this->assertTrue($field->isESNcardExclusive(), "Field $field->name should be ESNcard exclusive.");
            } else {
                $this->assertFalse($field->isESNcardExclusive(), "Field $field->name should not be ESNcard exclusive.");
            }
        }
    }

    public function testPermissions(): void
    {
        $this->assertEquals(
            ['approve applications', 'reject applications', 'mark applications as paid', 'blacklist applications', 'issue cards', 'deliver cards'],
            ApplicationField::ApprovalStatus->permissions()
        );

        $approveOnly = [
            ApplicationField::PassToken,
            ApplicationField::PaymentLink,
            ApplicationField::PaymentLinkID,
            ApplicationField::DateApproved,
        ];
        foreach ($approveOnly as $field) {
            $this->assertEquals(['approve applications'], $field->permissions());
        }

        $this->assertEquals(['scan cards'], ApplicationField::DateLastScanned->permissions());

        $this->assertEquals(['mark applications as paid'], ApplicationField::ESNcardNumber->permissions());
        $this->assertEquals(['mark applications as paid'], ApplicationField::DatePaid->permissions());

        $defaultPerms = [
            ApplicationField::Name,
            ApplicationField::Surname,
            ApplicationField::Email,
            ApplicationField::Nationality,
            ApplicationField::DateOfBirth,
            ApplicationField::Section,
            ApplicationField::MobilityStatus,
            ApplicationField::HostInstitution,
            ApplicationField::StatusProofFileID,
            ApplicationField::IdentityDocumentFileID,
            ApplicationField::FacePhotoFileID,
            ApplicationField::HasVerifiedEmail,
            ApplicationField::HasVerifiedID,
            ApplicationField::HasVerifiedStatus,
            ApplicationField::HasESNcard,
            ApplicationField::DateCreated,
            ApplicationField::DateLastModified,
        ];
        foreach ($defaultPerms as $field) {
            $this->assertEquals(['edit applications'], $field->permissions());
        }
    }

    public function testDefault(): void
    {
        $booleans = [
            ApplicationField::HasESNcard,
            ApplicationField::HasVerifiedEmail,
            ApplicationField::HasVerifiedID,
            ApplicationField::HasVerifiedStatus,
        ];
        foreach ($booleans as $field) {
            $this->assertSame(0, $field->default());
        }

        $this->assertSame('Pending', ApplicationField::ApprovalStatus->default());

        $nullDefaults = [
            ApplicationField::Name,
            ApplicationField::Surname,
            ApplicationField::Email,
            ApplicationField::Nationality,
            ApplicationField::DateOfBirth,
            ApplicationField::Section,
            ApplicationField::MobilityStatus,
            ApplicationField::HostInstitution,
            ApplicationField::StatusProofFileID,
            ApplicationField::IdentityDocumentFileID,
            ApplicationField::FacePhotoFileID,
            ApplicationField::PassToken,
            ApplicationField::ESNcardNumber,
            ApplicationField::PaymentLink,
            ApplicationField::PaymentLinkID,
            ApplicationField::DateCreated,
            ApplicationField::DateApproved,
            ApplicationField::DatePaid,
            ApplicationField::DateLastScanned,
            ApplicationField::DateLastModified,
        ];
        foreach ($nullDefaults as $field) {
            $this->assertNull($field->default());
        }
    }

    public function testSettings(): void
    {
        $max255 = [
            ApplicationField::Name,
            ApplicationField::Surname,
            ApplicationField::Email,
            ApplicationField::HostInstitution,
            ApplicationField::PaymentLink,
            ApplicationField::PaymentLinkID,
            ApplicationField::ApprovalStatus,
        ];
        foreach ($max255 as $field) {
            $this->assertEquals(['max_length' => 255], $field->settings());
        }

        $this->assertEquals(['max_length' => 128], ApplicationField::Nationality->settings());

        $max64 = [
            ApplicationField::MobilityStatus,
            ApplicationField::PassToken,
            ApplicationField::ESNcardNumber,
            ApplicationField::DateCreated,
            ApplicationField::DateApproved,
            ApplicationField::DatePaid,
            ApplicationField::DateLastScanned,
            ApplicationField::DateLastModified,
        ];
        foreach ($max64 as $field) {
            $this->assertEquals(['max_length' => 64], $field->settings());
        }

        $this->assertEquals(['max_length' => 20], ApplicationField::DateOfBirth->settings());

        $files = [
            ApplicationField::StatusProofFileID,
            ApplicationField::IdentityDocumentFileID,
            ApplicationField::FacePhotoFileID,
        ];
        foreach ($files as $field) {
            $this->assertEquals(['target_type' => 'file'], $field->settings());
        }

        $emptySettings = [
            ApplicationField::Section,
            ApplicationField::HasVerifiedEmail,
            ApplicationField::HasVerifiedID,
            ApplicationField::HasVerifiedStatus,
            ApplicationField::HasESNcard,
        ];
        foreach ($emptySettings as $field) {
            $this->assertEquals([], $field->settings());
        }
    }
}
