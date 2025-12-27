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

class GoogleService
{
    protected ConfigFactoryInterface $configFactory;
    protected LoggerChannelInterface $logger;
    protected ?GoogleClient $client = NULL;
    protected ?Walletobjects $walletService = NULL;
    protected string $passClassID = '';

    public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->configFactory = $config_factory;
        $this->logger = $logger_factory->get('esn_membership_manager');
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
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    private function getPassClass(): string
    {
        if (!empty($this->passClassID))
            return $this->passClassID;

        $client = $this->getClient();
        if (!$client)
            throw new Exception('Google Service Account credentials were not configured.');

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');
        if (empty($issuerID))
            throw new Exception('Google Wallet not configured.');
        $classID = "{$issuerID}.esn_membership_manager_pass";
        try {
            $this->walletService->genericclass->get($classID);
            $this->passClassID = $classID;
            return $classID;
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'classNotFound') {
                throw $error;
            }
        }

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
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    public function getESNcardObject(array $data): string
    {

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');
        $classID = $this->getPassClass();

        try {
            $objectID = "$issuerID.esncard-{$data['id']}";
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
        }

        $paidDate = new DateTime(substr($data['paid_date'], 0, -7));
        $expiryDate = (new DateTime(substr($data['paid_date'], 0, -7)))->add(new DateInterval("P1Y"));

        $object = new GenericObject([
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
                    'body' => $paidDate->sub(new DateInterval("P1Y"))->format('d/m/Y')
                ]
            ],
            'state' => 'ACTIVE',
            'passConstraints' => ['screenshotEligibility' => 'INELIGIBLE']
        ]);

        $this->walletService->genericobject->insert($object);
        return $this->getLink($objectID);
    }

    /**
     * @throws \Google\Service\Exception
     * @throws Exception
     */
    public function getFreePassObject(array $data): string
    {

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $issuerID = $config->get('google_issuer_id');
        $classID = $this->getPassClass();

        try {
            $objectID = "$issuerID.free_pass-{$data['id']}";
            $this->walletService->genericobject->get($objectID);
            return $this->getLink($objectID);
        } catch (\Google\Service\Exception $error) {
            if (empty($error->getErrors()) || $error->getErrors()[0]['reason'] != 'resourceNotFound') {
                throw $error;
            }
        }

        $approvedDate = new DateTime(substr($data['date_approved'], 0, -7));
        $expiryDate = (new DateTime(substr($data['date_approved'], 0, -7)))->add(new DateInterval("P1Y"));


        $object = new GenericObject([
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
                    'uri' => 'https://esncy.org/sites/default/files/2025-12/Free_Pass_Hero.png'
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

        $this->walletService->genericobject->insert($object);
        return $this->getLink($objectID);
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
}