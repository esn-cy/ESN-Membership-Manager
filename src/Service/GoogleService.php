<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Service;

use DateInterval;
use DateTimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\omnia\Service\FileServiceBase;
use Drupal\omnia\Service\GoogleServiceBase;
use Exception;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Walletobjects\Barcode;
use Google\Service\Walletobjects\GenericClass;
use Google\Service\Walletobjects\GenericObject;
use Google\Service\Walletobjects\Image;
use Google\Service\Walletobjects\ImageUri;
use Google\Service\Walletobjects\LocalizedString;
use Google\Service\Walletobjects\TimeInterval;
use Google\Service\Walletobjects\TranslatedString;
use GuzzleHttp\Exception\GuzzleException;

class GoogleService extends GoogleServiceBase
{
    protected string $cardClassID = '';
    protected string $passClassID = '';
    protected string $guestClassID = '';

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        FileServiceBase $fileService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configFactory, $fileService, $loggerFactory);
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public function appendRow(array $data): bool
    {
        $client = $this->getClient();
        if (!$client) {
            return FALSE;
        }

        $membershipSettings = new MembershipSettings($this->configFactory);
        $spreadsheetId = $membershipSettings->getSpreadsheetID();
        $range = $membershipSettings->getSheetName() . '!A:H';

        $service = new Sheets($client);

        $values = [
            [
                $data['date'] ?? str_replace('-', '/', date('d-m-y')),
                $data['name'] ?? '',
                $data['card_number'] ?? '',
                $data['pos'] ?? '',
                $data['host'] ?? '',
                $data['nationality'] ?? '',
                $data['mop'] ?? '',
                $data['amount'] ?? 0,
            ]
        ];

        $body = new ValueRange([
            'values' => $values
        ]);

        $params = [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS'
        ];

        try {
            $result = $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

            if ($result->getUpdates()->getUpdatedCells() > 0) {
                return TRUE;
            }
            return FALSE;
        } catch (Exception $e) {
            $this->logger->error('Google Sheets Append Error: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }

    /**
     * @throws Exception
     */
    public function getESNcardClass(): string
    {
        if (!empty($this->cardClassID)) {
            return $this->cardClassID;
        }

        $classID = $this->getClass('esn_membership_manager_card');
        if (empty($classID)) {
            $omniaSettings = new OmniaSettings($this->configFactory);
            $classID = "{$omniaSettings->getGoogleIssuerID()}.esn_membership_manager_card";
            $class = new GenericClass([
                'id' => $classID,
                'classTemplateInfo' => [
                    'cardTemplateOverride' => [
                        'cardRowTemplateInfos' => [
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.imageModulesData[\'face_photo\']']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'nationality\']']]]]
                                ]
                            ],
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'dob\']', 'date_format' => 'DATE_YEAR']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'studies_at\']']]]]
                                ]
                            ],
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'esn_section\']']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'valid_since\']', 'date_format' => 'DATE_YEAR']]]]
                                ]
                            ]
                        ]
                    ],
                    'detailsTemplateOverride' => [
                        'detailsItemInfos' => [
                            'item' => [
                                'firstValue' => ['fields' => [['fieldPath' => 'class.textModulesData[\'local_disclaimer\']']]]
                            ]
                        ]
                    ]
                ],
                'textModulesData' => [
                    [
                        'id' => 'local_disclaimer',
                        'header' => 'Disclaimer',
                        'body' => 'This ESNcard can only be used in local events.'
                    ]
                ],
                'securityAnimation' => ['animationType' => 'FOIL_SHIMMER'],
                'multipleDevicesAndHoldersAllowedStatus' => 'ONE_USER_ALL_DEVICES',
                'viewUnlockRequirement' => 'UNLOCK_NOT_REQUIRED'
            ]);

            return $this->createClass($class);
        } else {
            return $classID;
        }
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function getPassClass(): string
    {
        if (!empty($this->passClassID)) {
            return $this->passClassID;
        }

        $classID = $this->getClass('esn_membership_manager_pass');
        if (empty($classID)) {
            $omniaSettings = new OmniaSettings($this->configFactory);
            $classID = "{$omniaSettings->getGoogleIssuerID()}.esn_membership_manager_pass";
            $class = new GenericClass([
                'id' => $classID,
                'classTemplateInfo' => [
                    'cardTemplateOverride' => [
                        'cardRowTemplateInfos' => [
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'nationality\']']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'dob\']', 'date_format' => 'DATE_YEAR']]]]
                                ]
                            ],
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'mobility_status\']']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'valid_since\']', 'date_format' => 'DATE_YEAR']]]]
                                ]
                            ]
                        ]
                    ],
                    'detailsTemplateOverride' => [
                        'detailsItemInfos' => [
                            'item' => [
                                'firstValue' => ['fields' => [['fieldPath' => 'class.textModulesData[\'local_disclaimer\']']]]
                            ]
                        ]
                    ]
                ],
                'textModulesData' => [
                    [
                        'id' => 'local_disclaimer',
                        'header' => 'Disclaimer',
                        'body' => 'This pass can only be used in local events.'
                    ]
                ],
                'securityAnimation' => ['animationType' => 'FOIL_SHIMMER'],
                'multipleDevicesAndHoldersAllowedStatus' => 'ONE_USER_ALL_DEVICES',
                'viewUnlockRequirement' => 'UNLOCK_NOT_REQUIRED'
            ]);

            return $this->createClass($class);
        } else {
            return $classID;
        }
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function getGuestPassClass(): string
    {
        if (!empty($this->guestClassID)) {
            return $this->guestClassID;
        }

        $classID = $this->getClass('esn_membership_manager_guest');
        if (empty($classID)) {
            $omniaSettings = new OmniaSettings($this->configFactory);
            $classID = "{$omniaSettings->getGoogleIssuerID()}.esn_membership_manager_guest";
            $class = new GenericClass([
                'id' => $classID,
                'classTemplateInfo' => [
                    'cardBarcodeSectionDetails' => [
                        'firstTopDetail' => [
                            'fieldSelector' => [
                                'fields' => [
                                    ['fieldPath' => 'class.imageModulesData[\'id_required\']']
                                ]
                            ]
                        ]
                    ],
                    'cardTemplateOverride' => [
                        'cardRowTemplateInfos' => [
                            [
                                'oneItem' => [
                                    'item' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'referer_name\']']]]],
                                ]
                            ],
                            [
                                'twoItems' => [
                                    'startItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'referer_mobility_status\']']]]],
                                    'endItem' => ['firstValue' => ['fields' => [['fieldPath' => 'object.textModulesData[\'valid_until\']', 'date_format' => 'DATE_YEAR']]]]
                                ]
                            ]
                        ]
                    ],
                    'detailsTemplateOverride' => [
                        'detailsItemInfos' => [
                            [
                                'item' => [
                                    'firstValue' => ['fields' => [['fieldPath' => 'class.textModulesData[\'local_disclaimer\']']]]
                                ]
                            ],
                            [
                                'item' => [
                                    'firstValue' => ['fields' => [['fieldPath' => 'class.textModulesData[\'guest_disclaimer\']']]]
                                ]
                            ]
                        ]
                    ]
                ],
                'textModulesData' => [
                    [
                        'id' => 'local_disclaimer',
                        'header' => 'Disclaimer',
                        'body' => 'This pass can only be used in local events.'
                    ],
                    [
                        'id' => 'guest_disclaimer',
                        'header' => 'Disclaimer',
                        'body' => 'To redeem this pass you will need to present valid ID at the door as well as arrive at the venue with the person that invited you.'
                    ]
                ],
                'imageModulesData' => [
                    [
                        'id' => 'id_required',
                        'mainImage' => [
                            'sourceUri' => [
                                'uri' => 'https://esncy.org/sites/default/files/2026-04/guest-pass-id.png'
                            ],
                            'contentDescription' => new LocalizedString([
                                'defaultValue' => new TranslatedString([
                                    'language' => 'en-US',
                                    'value' => 'Valid ID Required'
                                ])
                            ])
                        ]
                    ]
                ],
                'multipleDevicesAndHoldersAllowedStatus' => 'MULTIPLE_HOLDERS',
                'viewUnlockRequirement' => 'UNLOCK_NOT_REQUIRED'
            ]);

            return $this->createClass($class);
        } else {
            return $classID;
        }
    }

    /**
     * @throws \Google\Service\Exception
     * @throws GuzzleException
     */
    public function getESNcardObject(ApplicationInterface $application): ?string
    {
        $link = $this->getObject("esncard-{$application->id()}");
        if (empty($link)) {
            $object = $this->createESNcardObject($application);
            return $this->createObject($object);
        } else {
            return $link;
        }
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     * @throws GuzzleException
     */
    private function createESNcardObject(ApplicationInterface $application): GenericObject
    {
        $omniaSettings = new OmniaSettings($this->configFactory);

        $objectID = "{$omniaSettings->getGoogleIssuerID()}.esncard-{$application->id()}";
        $classID = $this->getESNcardClass();
        $paidDate = $application->getDatePaid();
        $paidDate->setTime(0, 0);
        $expiryDate = (clone $paidDate)->add(new DateInterval("P1Y"));
        $privateImageID = $this->uploadPrivateImage($application->getFacePhoto()->id());

        return new GenericObject([
            'genericType' => 'GENERIC_OTHER',
            'cardTitle' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => 'ESNcard'
                ])
            ]),
            'subheader' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => 'Member of the Erasmus Generation'
                ])
            ]),
            'header' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $application->getFullName()
                ])
            ]),
            'logo' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2025-12/ESN_Logo.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'ESN Logo'
                    ])
                ])
            ]),
            'hexBackgroundColor' => '#2e3192',
            'id' => $objectID,
            'classId' => $classID,
            'barcode' => new Barcode([
                'type' => 'CODE_128',
                'value' => $application->getValue(ApplicationField::ESNcardNumber),
                'alternateText' => $application->getValue(ApplicationField::ESNcardNumber)
            ]),
            'heroImage' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2025-12/Google_Wallet_Hero.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'ESNcard Logo'
                    ])
                ])
            ]),
            'validTimeInterval' => new TimeInterval([
                'start' => ['date' => $paidDate->format(DateTimeInterface::ATOM)],
                'end' => ['date' => $expiryDate->format(DateTimeInterface::ATOM)]
            ]),
            'imageModulesData' => [
                [
                    'id' => 'face_photo',
                    'mainImage' => new Image([
                        'privateImageId' => $privateImageID,
                        'contentDescription' => new LocalizedString([
                            'defaultValue' => new TranslatedString([
                                'language' => 'en-US',
                                'value' => 'Cardholder Photo'
                            ])
                        ])
                    ])
                ]
            ],
            'textModulesData' => [
                [
                    'id' => 'nationality',
                    'header' => 'Nationality',
                    'body' => $application->getValue(ApplicationField::Nationality)
                ],
                [
                    'id' => 'dob',
                    'header' => 'Date of Birth',
                    'body' => $application->getDateOfBirth()->format('d/m/Y')
                ],
                [
                    'id' => 'studies_at',
                    'header' => 'Studies at',
                    'body' => $application->getValue(ApplicationField::HostInstitution)
                ],
                [
                    'id' => 'esn_section',
                    'header' => 'ESN Section',
                    'body' => $application->getValue(ApplicationField::Section)
                ],
                [
                    'id' => 'valid_since',
                    'header' => 'Valid Since',
                    'body' => $paidDate->format('d/m/Y')
                ]
            ],
            'state' => 'ACTIVE',
            'passConstraints' => ['screenshotEligibility' => 'INELIGIBLE']
        ]);
    }

    /**
     * @throws \Google\Service\Exception
     * @throws GuzzleException
     */
    public function getFreePassObject(ApplicationInterface $application): string
    {
        $link = $this->getObject("pass-{$application->id()}");
        if (empty($link)) {
            $object = $this->createFreePassObject($application);
            return $this->createObject($object);
        } else {
            return $link;
        }
    }


    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function createFreePassObject(ApplicationInterface $application): GenericObject
    {
        $membershipSettings = new MembershipSettings($this->configFactory);
        $omniaSettings = new OmniaSettings($this->configFactory);

        $objectID = "{$omniaSettings->getGoogleIssuerID()}.pass-{$application->id()}";
        $classID = $this->getPassClass();
        $approvedDate = $application->getDateApproved();
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P1Y"));

        return new GenericObject([
            'genericType' => 'GENERIC_OTHER',
            'cardTitle' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $membershipSettings->getPassName()
                ])
            ]),
            'subheader' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => 'Verified Mobility Participant'
                ])
            ]),
            'header' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $application->getFullName()
                ])
            ]),
            'logo' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2025-12/ESN_Logo.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'ESN Logo'
                    ])
                ])
            ]),
            'hexBackgroundColor' => '#00aeef',
            'id' => $objectID,
            'classId' => $classID,
            'barcode' => new Barcode([
                'type' => 'QR_CODE',
                'value' => $application->getValue(ApplicationField::PassToken),
                'alternateText' => strtoupper($application->getValue(ApplicationField::PassToken)),
            ]),
            'heroImage' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2026-01/emm-hero.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'Free Pass Logo'
                    ])
                ])
            ]),
            'validTimeInterval' => new TimeInterval([
                'start' => ['date' => $approvedDate->format(DateTimeInterface::ATOM)],
                'end' => ['date' => $expiryDate->format(DateTimeInterface::ATOM)]
            ]),
            'textModulesData' => [
                [
                    'id' => 'nationality',
                    'header' => 'Nationality',
                    'body' => $application->getValue(ApplicationField::Nationality)
                ],
                [
                    'id' => 'dob',
                    'header' => 'Date of Birth',
                    'body' => $application->getDateOfBirth()->format('d/m/Y')
                ],
                [
                    'id' => 'mobility_status',
                    'header' => 'Mobility Status',
                    'body' => $application->getValue(ApplicationField::MobilityStatus)
                ],
                [
                    'id' => 'valid_since',
                    'header' => 'Valid Since',
                    'body' => $approvedDate->format('d/m/Y')
                ]
            ],
            'state' => 'ACTIVE',
            'passConstraints' => ['screenshotEligibility' => 'INELIGIBLE']
        ]);
    }

    /**
     * @throws \Google\Service\Exception
     * @throws GuzzleException
     */
    public function getGuestPassObject(GuestPassInterface $guestPass, ApplicationInterface $referrer): string
    {
        $link = $this->getObject("guest-{$guestPass->id()}");
        if (empty($link)) {
            $object = $this->createGuestPassObject($guestPass, $referrer);
            return $this->createObject($object);
        } else {
            return $link;
        }
    }


    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function createGuestPassObject(GuestPassInterface $guestPass, ApplicationInterface $referrer): GenericObject
    {
        $membershipSettings = new MembershipSettings($this->configFactory);
        $omniaSettings = new OmniaSettings($this->configFactory);

        $objectID = "{$omniaSettings->getGoogleIssuerID()}.guest-{$guestPass->id()}";
        $classID = $this->getGuestPassClass();
        $approvedDate = $guestPass->getDateApproved();
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P7D"));

        return new GenericObject([
            'genericType' => 'GENERIC_OTHER',
            'cardTitle' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $membershipSettings->getGuestPassName()
                ])
            ]),
            'subheader' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => 'Approved Guest'
                ])
            ]),
            'header' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $guestPass->getFullName()
                ])
            ]),
            'logo' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2025-12/ESN_Logo.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'ESN Logo'
                    ])
                ])
            ]),
            'hexBackgroundColor' => '#ec008c',
            'id' => $objectID,
            'classId' => $classID,
            'barcode' => new Barcode([
                'type' => 'AZTEC',
                'value' => $guestPass->getValue(ApplicationField::PassToken),
                'alternateText' => strtoupper($guestPass->getValue(ApplicationField::PassToken)),
            ]),
            'heroImage' => new Image([
                'sourceUri' => new ImageUri([
                    'uri' => 'https://esncy.org/sites/default/files/2026-01/emm-hero.png'
                ]),
                'contentDescription' => new LocalizedString([
                    'defaultValue' => new TranslatedString([
                        'language' => 'en-US',
                        'value' => 'ESN Membership Manager'
                    ])
                ])
            ]),
            'validTimeInterval' => new TimeInterval([
                'start' => ['date' => $approvedDate->format(DateTimeInterface::ATOM)],
                'end' => ['date' => $expiryDate->format(DateTimeInterface::ATOM)]
            ]),
            'textModulesData' => [
                [
                    'id' => 'referer_name',
                    'header' => 'Referer Name',
                    'body' => $referrer->getFullName()
                ],
                [
                    'id' => 'referer_mobility_status',
                    'header' => 'Referer Mobility Status',
                    'body' => $referrer->getValue(ApplicationField::MobilityStatus)
                ],
                [
                    'id' => 'valid_until',
                    'header' => 'Valid Until',
                    'body' => $expiryDate->format('d/m/Y')
                ]
            ],
            'state' => 'ACTIVE',
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function updateApplicationObject(ApplicationInterface|GuestPassInterface $application, string $type, ?ApplicationInterface $referrer = null): bool
    {
        $updateObject = match ($type) {
            'card' => $this->createESNcardObject($application),
            'pass' => $this->createFreePassObject($application),
            'guest' => $this->createGuestPassObject($application, $referrer),
            default => throw new Exception('Unsupported application type.'),
        };

        return $this->updateObject($updateObject);
    }

    /**
     * @throws Exception
     */
    public function deleteApplicationObject(string $applicationID, string $type): bool
    {
        $omniaSettings = new OmniaSettings($this->configFactory);

        $objectID = match ($type) {
            'card' => "{$omniaSettings->getGoogleIssuerID()}.esncard-$applicationID",
            'pass' => "{$omniaSettings->getGoogleIssuerID()}.pass-$applicationID",
            'guest' => "{$omniaSettings->getGoogleIssuerID()}.guest-$applicationID",
            default => throw new Exception('Unsupported application type.'),
        };

        return $this->deleteObject($objectID);
    }
}