<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Utility;

use Drupal\esn_membership_manager\Utility\MobilityStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MobilityStatuses utility.
 *
 * @covers \Drupal\esn_membership_manager\Utility\MobilityStatuses
 * @group esn_membership_manager
 */
class MobilityStatusesTest extends TestCase
{
    /**
     * Tests getGroupedOptions returns an array of grouped options.
     */
    public function testGetGroupedOptions(): void
    {
        $grouped = MobilityStatuses::getGroupedOptions();

        $this->assertIsArray($grouped);
        $this->assertArrayHasKey('Erasmus+ Programme', $grouped);
        $this->assertArrayHasKey('European Solidarity Corps', $grouped);
        $this->assertArrayHasKey('International Full Degree Student', $grouped);
        $this->assertArrayHasKey('Other Mobility Programme', $grouped);
        $this->assertArrayHasKey('ESN', $grouped);
        $this->assertArrayHasKey('Mobility Contributors', $grouped);

        $this->assertSame('Study Exchange', $grouped['Erasmus+ Programme']['erasmus_study']);
        $this->assertSame('ESN Volunteer', $grouped['ESN']['esn_volunteer']);
    }

    /**
     * Tests getFlatOptions flattens the nested statuses correctly.
     */
    public function testGetFlatOptions(): void
    {
        $flat = MobilityStatuses::getFlatOptions();

        $this->assertIsArray($flat);
        $this->assertSame('Study Exchange', $flat['erasmus_study']);
        $this->assertSame('European Solidarity Corps', $flat['esc']);
        $this->assertSame('Undergraduate', $flat['international_undergrad']);
        $this->assertSame('ESN Volunteer', $flat['esn_volunteer']);
        $this->assertSame('Buddy', $flat['mobility_buddy']);
    }

    /**
     * Tests getLabels for study/mundus/vet statuses (both standard and 'other').
     */
    public function testGetLabelsForStudyAndMundusAndVet(): void
    {
        // Standard study exchange: Learning Agreement
        $labels1 = MobilityStatuses::getLabels('erasmus_study');
        $this->assertSame('Host University', $labels1['organization_label']);
        $this->assertSame('Learning Agreement', $labels1['proof_label']);

        // Erasmus Mundus: Learning Agreement
        $labels2 = MobilityStatuses::getLabels('erasmus_mundus');
        $this->assertSame('Host University', $labels2['organization_label']);
        $this->assertSame('Learning Agreement', $labels2['proof_label']);

        // VET: Learning Agreement
        $labels3 = MobilityStatuses::getLabels('erasmus_train_vet');
        $this->assertSame('Host University', $labels3['organization_label']);
        $this->assertSame('Learning Agreement', $labels3['proof_label']);

        // Other study: Appropriate Certification
        $labels4 = MobilityStatuses::getLabels('other_study');
        $this->assertSame('Host University', $labels4['organization_label']);
        $this->assertSame('Appropriate Certification', $labels4['proof_label']);
    }

    /**
     * Tests getLabels for traineeship statuses (both standard and 'other').
     */
    public function testGetLabelsForTraineeship(): void
    {
        // Standard traineeship
        $labels1 = MobilityStatuses::getLabels('erasmus_train_traineeship');
        $this->assertSame('Host Organization', $labels1['organization_label']);
        $this->assertSame('Traineeship Certificate', $labels1['proof_label']);

        // Other traineeship
        $labels2 = MobilityStatuses::getLabels('other_train_traineeship');
        $this->assertSame('Host Organization', $labels2['organization_label']);
        $this->assertSame('Appropriate Certification', $labels2['proof_label']);
    }

    /**
     * Tests getLabels for European Solidarity Corps (esc).
     */
    public function testGetLabelsForESC(): void
    {
        $labels = MobilityStatuses::getLabels('esc');
        $this->assertSame('Host Organization', $labels['organization_label']);
        $this->assertSame('ESC Certificate', $labels['proof_label']);
    }

    /**
     * Tests getLabels for international students.
     */
    public function testGetLabelsForInternational(): void
    {
        $labels = MobilityStatuses::getLabels('international_undergrad');
        $this->assertSame('University', $labels['organization_label']);
        $this->assertSame('International Application / Certificate of Studies', $labels['proof_label']);
    }

    /**
     * Tests getLabels for ESN members (volunteer/alumnus).
     */
    public function testGetLabelsForESN(): void
    {
        $labels = MobilityStatuses::getLabels('esn_volunteer');
        $this->assertSame('ESN Section', $labels['organization_label']);
        $this->assertSame('ESN Certificate / Membership Proof', $labels['proof_label']);
    }

    /**
     * Tests getLabels for mobility contributors (buddy, mentor, etc.).
     */
    public function testGetLabelsForMobilityContributors(): void
    {
        $labels = MobilityStatuses::getLabels('mobility_buddy');
        $this->assertSame('University / Organization', $labels['organization_label']);
        $this->assertSame('Appropriate Certification', $labels['proof_label']);
    }

    /**
     * Tests getLabels fallback for other or unknown statuses.
     */
    public function testGetLabelsFallback(): void
    {
        $labels = MobilityStatuses::getLabels('other_volunteer');
        $this->assertSame('Host Institution', $labels['organization_label']);
        $this->assertSame('Appropriate Certification', $labels['proof_label']);

        $unknown = MobilityStatuses::getLabels('completely_unknown_status');
        $this->assertSame('Host Institution', $unknown['organization_label']);
        $this->assertSame('Appropriate Certification', $unknown['proof_label']);
    }
}
