<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Entity\GuestPass;

use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * Unit tests for GuestPassField enum.
 *
 * @covers \Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField
 * @group esn_membership_manager
 */
class GuestPassFieldTest extends MembershipManagerTestCase
{
    public function testCasesCount(): void
    {
        $this->assertCount(10, GuestPassField::cases());
    }

    public function testLabels(): void
    {
        $this->assertEquals('Referer ID', GuestPassField::RefererID->label());
        $this->assertEquals('First Name', GuestPassField::Name->label());
        $this->assertEquals('Last Name', GuestPassField::Surname->label());
        $this->assertEquals('Email', GuestPassField::Email->label());
        $this->assertEquals('Reason', GuestPassField::Reason->label());
        $this->assertEquals('Pass Token', GuestPassField::PassToken->label());
        $this->assertEquals('Date Created', GuestPassField::DateCreated->label());
        $this->assertEquals('Date Approved', GuestPassField::DateApproved->label());
        $this->assertEquals('Date Redeemed', GuestPassField::DateRedeemed->label());
        $this->assertEquals('Date Last Modified', GuestPassField::DateLastModified->label());
    }

    public function testTypes(): void
    {
        $this->assertEquals('entity_reference', GuestPassField::RefererID->type());
        $this->assertEquals('string', GuestPassField::Name->type());
        $this->assertEquals('string', GuestPassField::Surname->type());
        $this->assertEquals('string', GuestPassField::PassToken->type());
        $this->assertEquals('string_long', GuestPassField::Reason->type());
        $this->assertEquals('email', GuestPassField::Email->type());
        $this->assertEquals('datetime', GuestPassField::DateCreated->type());
        $this->assertEquals('datetime', GuestPassField::DateApproved->type());
        $this->assertEquals('datetime', GuestPassField::DateRedeemed->type());
        $this->assertEquals('datetime', GuestPassField::DateLastModified->type());
    }

    public function testRequired(): void
    {
        $requiredFields = [
            GuestPassField::RefererID,
            GuestPassField::Name,
            GuestPassField::Surname,
            GuestPassField::Email,
            GuestPassField::Reason,
            GuestPassField::DateCreated,
        ];

        foreach (GuestPassField::cases() as $field) {
            if (in_array($field, $requiredFields, true)) {
                $this->assertTrue($field->required(), "Field $field->name should be required.");
            } else {
                $this->assertFalse($field->required(), "Field $field->name should not be required.");
            }
        }
    }

    public function testUnique(): void
    {
        foreach (GuestPassField::cases() as $field) {
            if ($field === GuestPassField::PassToken) {
                $this->assertTrue($field->unique(), "PassToken should be unique.");
            } else {
                $this->assertFalse($field->unique(), "Field $field->name should not be unique.");
            }
        }
    }

    public function testUnlimitedCardinality(): void
    {
        foreach (GuestPassField::cases() as $field) {
            $this->assertFalse($field->unlimitedCardinality(), "Field $field->name should not have unlimited cardinality.");
        }
    }

    public function testDefault(): void
    {
        foreach (GuestPassField::cases() as $field) {
            $this->assertNull($field->default(), "Field $field->name should default to null.");
        }
    }

    public function testSettings(): void
    {
        $max255 = [
            GuestPassField::Name,
            GuestPassField::Surname,
            GuestPassField::Email,
        ];
        foreach ($max255 as $field) {
            $this->assertEquals(['max_length' => 255], $field->settings());
        }

        $max64 = [
            GuestPassField::PassToken,
            GuestPassField::DateCreated,
            GuestPassField::DateApproved,
            GuestPassField::DateRedeemed,
            GuestPassField::DateLastModified,
        ];
        foreach ($max64 as $field) {
            $this->assertEquals(['max_length' => 64], $field->settings());
        }

        $this->assertEquals(['target_type' => 'membership_application'], GuestPassField::RefererID->settings());
        $this->assertEquals([], GuestPassField::Reason->settings());
    }
}
