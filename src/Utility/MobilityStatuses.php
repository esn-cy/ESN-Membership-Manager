<?php

namespace Drupal\esn_membership_manager\Utility;

class MobilityStatuses
{
    private const statuses = [
        'Erasmus+ Programme' => [
            'erasmus_study' => 'Study Exchange',
            'erasmus_train_traineeship' => 'Traineeship',
            'erasmus_train_internship' => 'Internship',
            'erasmus_train_apprenticeship' => 'Apprenticeship',
            'erasmus_train_vet' => 'VET',
            'erasmus_mundus' => 'Erasmus Mundus Joint Masters',
        ],
        'European Solidarity Corps' => [
            'esc' => 'European Solidarity Corps',
        ],
        'International Full Degree Student' => [
            'international_undergrad' => 'Undergraduate',
            'international_postgrad' => 'Postgraduate',
        ],
        'Other Mobility Programme' => [
            'other_study' => 'Study Exchange (Other)',
            'other_train_traineeship' => 'Traineeship (Other)',
            'other_train_internship' => 'Internship (Other)',
            'other_train_apprenticeship' => 'Apprenticeship (Other)',
            'other_volunteer' => 'Volunteer (non-ESN)',
        ],
        'ESN' => [
            'esn_volunteer' => 'ESN Volunteer',
            'esn_alumnus' => 'ESN Alumnus',
        ],
        'Mobility Contributors' => [
            'mobility_buddy' => 'Buddy',
            'mobility_mentor' => 'Mentor',
            'mobility_ambassador' => 'Mobility Ambassador',
        ]
    ];

    /**
     * Get the nested array of mobility statuses for Select fields.
     */
    public static function getGroupedOptions(): array
    {
        return self::statuses;
    }

    /**
     * Get a flattened array mapping status keys to their labels.
     */
    public static function getFlatOptions(): array
    {
        $flat = [];
        foreach (self::statuses as $group) {
            foreach ($group as $key => $value) {
                $flat[$key] = $value;
            }
        }
        return $flat;
    }

    /**
     * Get the organization label and proof of status label for a specific status.
     *
     * @param string $status
     * @return array
     */
    public static function getLabels(string $status): array
    {
        $organizationLabel = 'Host Institution';
        $proofLabelText = 'Appropriate Certification';
        if (str_contains($status, '_study') || str_contains($status, '_mundus') || str_contains($status, '_vet')) {
            $organizationLabel = 'Host University';
            $proofLabelText = str_starts_with($status, 'other') ? 'Appropriate Certification' : 'Learning Agreement';
        } elseif (str_contains($status, '_train_')) {
            $organizationLabel = 'Host Organization';
            $proofLabelText = str_starts_with($status, 'other') ? 'Appropriate Certification' : 'Traineeship Certificate';
        } elseif ($status == 'esc') {
            $organizationLabel = 'Host Organization';
            $proofLabelText = 'ESC Certificate';
        } elseif (str_starts_with($status, 'international_')) {
            $organizationLabel = 'University';
            $proofLabelText = 'International Application / Certificate of Studies';
        } elseif (str_starts_with($status, 'esn_')) {
            $organizationLabel = 'ESN Section';
            $proofLabelText = 'ESN Certificate / Membership Proof';
        } elseif (str_starts_with($status, 'mobility_')) {
            $organizationLabel = 'University / Organization';
            $proofLabelText = 'Appropriate Certification';
        }
        return [
            'organization_label' => $organizationLabel,
            'proof_label' => $proofLabelText,
        ];
    }
}