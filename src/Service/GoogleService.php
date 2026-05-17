<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Service;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use Firebase\JWT\JWT;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;
use Google\Service\Walletobjects;
use Google\Service\Walletobjects\Barcode;
use Google\Service\Walletobjects\GenericClass;
use Google\Service\Walletobjects\GenericObject;
use Google\Service\Walletobjects\Image;
use Google\Service\Walletobjects\ImageUri;
use Google\Service\Walletobjects\LocalizedString;
use Google\Service\Walletobjects\TimeInterval;
use Google\Service\Walletobjects\TranslatedString;
use GuzzleHttp\Exception\GuzzleException;

class GoogleService
{
    protected ConfigFactoryInterface $configFactory;
    protected FileService $fileService;
    protected LoggerChannelInterface $logger;
    protected ?GoogleClient $client = NULL;
    protected ?Walletobjects $walletService = NULL;
    protected string $cardClassID = '';
    protected string $passClassID = '';
    protected string $guestClassID = '';

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        FileService $fileService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->fileService = $fileService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }


    protected function getClient(): ?GoogleClient
    {
        if ($this->client) {
            return $this->client;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $clientEmail = $moduleConfig->get('google_client_email');
        $privateKey = $moduleConfig->get('google_private_key');

        if (empty($clientEmail) || empty($privateKey)) {
            $this->logger->error('Google Service Account credentials were not configured.');
            return NULL;
        }

        $privateKey = str_replace("\\n", "\n", $privateKey);

        $authConfig = [
            'type' => 'service_account',
            'project_id' => $moduleConfig->get('google_project_id'),
            'private_key_id' => $moduleConfig->get('google_private_key_id'),
            'private_key' => $privateKey,
            'client_email' => $clientEmail,
            'client_id' => $moduleConfig->get('google_client_id'),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/' . urlencode($clientEmail),
        ];

        try {
            $client = new GoogleClient();
            $client->setApplicationName('ESN Membership Manager');
            $client->setScopes([Sheets::SPREADSHEETS, Walletobjects::WALLET_OBJECT_ISSUER]);
            $client->setAuthConfig($authConfig);
            $client->setAccessType('offline');
            $this->client = $client;

            $this->walletService = new Walletobjects($this->client);

            return $client;
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize Google Client: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }

    public function appendRow(array $data): bool
    {
        $client = $this->getClient();
        if (!$client) {
            return FALSE;
        }

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $spreadsheetId = $config->get('google_spreadsheet_id');
        $range = $config->get('google_sheet_name') ?: 'Data' . '!A:H';

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
    private function getClass(string $type): ?string
    {
        switch ($type) {
            case 'card':
                if (!empty($this->cardClassID)) {
                    return $this->cardClassID;
                }
                break;
            case 'pass':
                if (!empty($this->passClassID)) {
                    return $this->passClassID;
                }
                break;
            case 'guest':
                if (!empty($this->guestClassID)) {
                    return $this->guestClassID;
                }
                break;
            default:
                throw new Exception("Unsupported class type");
        }

        $client = $this->getClient();
        if (!$client)
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');
        $classID = "$issuerID.esn_membership_manager_$type";

        try {
            $this->walletService->genericclass->get($classID);
            switch ($type) {
                case 'card':
                    $this->cardClassID = $classID;
                    break;
                case 'pass':
                    $this->passClassID = $classID;
                    break;
                case 'guest':
                    $this->guestClassID = $classID;
                    break;
            }
            return $classID;
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'classNotFound') {
                throw $error;
            }
        }
        return "CLASS_NOT_FOUND_$classID";
    }


    /**
     * @throws Exception
     */
    public function getESNcardClass(): string
    {
        $classID = $this->getClass('card');
        if (str_starts_with($classID, 'CLASS_NOT_FOUND_')) {
            $classID = str_replace('CLASS_NOT_FOUND_', '', $classID);
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

            $response = $this->walletService->genericclass->insert($class);
            $this->passClassID = $response->id;
            return $response->id;
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
        $classID = $this->getClass('pass');
        if (str_starts_with($classID, 'CLASS_NOT_FOUND_')) {
            $classID = str_replace('CLASS_NOT_FOUND_', '', $classID);
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

            $response = $this->walletService->genericclass->insert($class);
            $this->passClassID = $response->id;
            return $response->id;
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
        $classID = $this->getClass('guest');
        if (str_starts_with($classID, 'CLASS_NOT_FOUND_')) {
            $classID = str_replace('CLASS_NOT_FOUND_', '', $classID);
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

            $response = $this->walletService->genericclass->insert($class);
            $this->guestClassID = $response->id;
            return $response->id;
        } else {
            return $classID;
        }
    }

    /**
     * @throws Exception
     */
    private function getLink(string $objectID): string
    {
        if (!$this->getClient())
            throw new Exception('Google Service Account credentials were not configured.');

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $clientEmail = $moduleConfig->get('google_client_email');
        $privateKey = $moduleConfig->get('google_private_key');

        $claims = [
            'iss' => $clientEmail,
            'aud' => 'google',
            'origins' => ['esncy.org'],
            'typ' => 'savetowallet',
            'payload' => [
                'genericObjects' => [
                    ['id' => $objectID]
                ]
            ]
        ];

        $token = JWT::encode(
            $claims,
            $privateKey,
            'RS256'
        );

        return "https://pay.google.com/gp/v/save/$token";
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     * @throws GuzzleException
     */
    public function getESNcardObject(array $data): string
    {
        if (!$this->getClient())
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        try {
            $objectID = "$issuerID.esncard-{$data['id']}";
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
        }

        $object = $this->createESNcardObject($data);

        $this->walletService->genericobject->insert($object);
        return $this->getLink($objectID);
    }


    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     * @throws GuzzleException
     */
    private function createESNcardObject(array $data): GenericObject
    {
        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        $objectID = "$issuerID.esncard-{$data['id']}";
        $classID = $this->getESNcardClass();
        $paidDate = new DateTime($data['date_paid']);
        $paidDate->setTime(0, 0);
        $expiryDate = (clone $paidDate)->add(new DateInterval("P1Y"));
        $privateImageID = $this->uploadPrivateImage($data['face_photo_fid']);

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
                    'value' => "{$data['name']} {$data['surname']}"
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
                'value' => $data['esncard_number'],
                'alternateText' => $data['esncard_number']
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
                    'body' => $data['nationality']
                ],
                [
                    'id' => 'dob',
                    'header' => 'Date of Birth',
                    'body' => (new DateTime($data['dob']))->format('d/m/Y')
                ],
                [
                    'id' => 'studies_at',
                    'header' => 'Studies at',
                    'body' => $data['host_institution']
                ],
                [
                    'id' => 'esn_section',
                    'header' => 'ESN Section',
                    'body' => $data['section']
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
     * @throws Exception
     */
    public function getFreePassObject(array $data): string
    {
        if (!$this->getClient())
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        try {
            $objectID = "$issuerID.free_pass-{$data['id']}";
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
        }

        $object = $this->createFreePassObject($data);

        $this->walletService->genericobject->insert($object);
        return $this->getLink($objectID);
    }


    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function createFreePassObject(array $data): GenericObject
    {
        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        $objectID = "$issuerID.free_pass-{$data['id']}";
        $classID = $this->getPassClass();
        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P1Y"));

        return new GenericObject([
            'genericType' => 'GENERIC_OTHER',
            'cardTitle' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $config->get('scheme_name')
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
                    'value' => "{$data['name']} {$data['surname']}"
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
                'value' => $data['pass_token'],
                'alternateText' => strtoupper($data['pass_token']),
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
                    'body' => $data['nationality']
                ],
                [
                    'id' => 'dob',
                    'header' => 'Date of Birth',
                    'body' => (new DateTime($data['dob']))->format('d/m/Y')
                ],
                [
                    'id' => 'mobility_status',
                    'header' => 'Mobility Status',
                    'body' => $data['mobility_status']
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
     * @throws Exception
     */
    public function getGuestPassObject(array $data): string
    {
        if (!$this->getClient())
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        try {
            $objectID = "$issuerID.guest_pass-{$data['id']}";
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
        }

        $object = $this->createGuestPassObject($data);

        $this->walletService->genericobject->insert($object);
        return $this->getLink($objectID);
    }


    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function createGuestPassObject(array $data): GenericObject
    {
        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        $objectID = "$issuerID.guest_pass-{$data['id']}";
        $classID = $this->getGuestPassClass();
        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P7D"));

        return new GenericObject([
            'genericType' => 'GENERIC_OTHER',
            'cardTitle' => new LocalizedString([
                'defaultValue' => new TranslatedString([
                    'language' => 'en-US',
                    'value' => $config->get('guest_scheme_name')
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
                    'value' => "{$data['name']} {$data['surname']}"
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
                'value' => $data['guest_pass_token'],
                'alternateText' => strtoupper($data['guest_pass_token']),
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
                    'body' => "{$data['referer_name']} {$data['referer_surname']}"
                ],
                [
                    'id' => 'referer_mobility_status',
                    'header' => 'Referer Mobility Status',
                    'body' => $data['referer_mobility_status']
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
    public function updateObject(array $application, string $type): bool
    {
        $client = $this->getClient();
        if (!$client)
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        $objectID = match ($type) {
            'card' => "$issuerID.esncard-{$application['id']}",
            'pass' => "$issuerID.free_pass-{$application['id']}",
            'guest' => "$issuerID.guest_pass-{$application['id']}",
            default => throw new Exception('Unsupported application type.'),
        };

        try {
            @$this->walletService->genericobject->get($objectID);
        } catch (\Google\Service\Exception) {
            return true;
        }

        $updateObject = match ($type) {
            'card' => $this->createESNcardObject($application),
            'pass' => $this->createFreePassObject($application),
            'guest' => $this->createGuestPassObject($application),
            default => throw new Exception('Unsupported application type.'),
        };

        try {
            @$this->walletService->genericobject->update($objectID, $updateObject);
        } catch (\Google\Service\Exception) {
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     */
    public function deleteObject(string $applicationID, string $type): bool
    {
        $client = $this->getClient();
        if (!$client)
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');

        $objectID = match ($type) {
            'card' => "$issuerID.esncard-$applicationID",
            'pass' => "$issuerID.free_pass-$applicationID",
            'guest' => "$issuerID.guest_pass-$applicationID",
            default => throw new Exception('Unsupported application type.'),
        };

        try {
            @$this->walletService->genericobject->get($objectID);
        } catch (\Google\Service\Exception) {
            return true;
        }

        $patchObject = new GenericObject([]);
        $patchObject->setState('EXPIRED');
        $patchObject->setTextModulesData([]);
        $patchObject->setImageModulesData([]);
        $patchObject->setHeader(new LocalizedString([
            'defaultValue' => new TranslatedString([
                'language' => 'en-US',
                'value' => ''
            ])
        ]));

        try {
            $this->walletService->genericobject->patch($objectID, $patchObject);
        } catch (\Google\Service\Exception) {
            return false;
        }
        return true;
    }

    /**
     * @throws Exception
     * @throws GuzzleException
     */
    private function uploadPrivateImage(string $fileID): string
    {
        $mimeType = $this->fileService->getFileMimeType($fileID);
        $imageContents = $this->fileService->readFile($fileID);
        if (empty($mimeType) || empty($imageContents)) {
            throw new Exception("Unable to read the image file.");
        }

        $client = $this->getClient();
        if (!$client) {
            throw new Exception('Google Service Account credentials were not configured.');
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $moduleConfig->get('google_issuer_id');
        if (empty($issuerID)) {
            throw new Exception('Google Wallet Issuer ID is missing from settings.');
        }

        $httpClient = $client->authorize();
        $response = $httpClient->request('POST', "https://walletobjects.googleapis.com/upload/walletobjects/v1/privateContent/$issuerID/uploadPrivateImage", [
            'headers' => [
                'Content-Type' => $mimeType,
            ],
            'body' => $imageContents,
        ]);

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new Exception("Failed to upload private image to Google Wallet. HTTP Status: $status");
        }

        $result = json_decode((string)$response->getBody(), TRUE);
        if (empty($result['privateImageId'])) {
            throw new Exception("Could not retrieve privateImageId from response.");
        }

        return $result['privateImageId'];
    }
}