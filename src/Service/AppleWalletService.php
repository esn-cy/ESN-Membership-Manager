<?php

namespace Drupal\esn_membership_manager\Service;

use DateInterval;
use DateTime;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use PKPass\PKPass;
use PKPass\PKPassException;

class AppleWalletService
{
    protected ConfigFactoryInterface $configFactory;
    protected Settings $settings;
    protected ModuleHandlerInterface $moduleHandler;
    protected FileService $fileService;
    protected ClientInterface $httpClient;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Settings                      $settings,
        ModuleHandlerInterface        $moduleHandler,
        FileService $fileService,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->settings = $settings;
        $this->moduleHandler = $moduleHandler;
        $this->fileService = $fileService;
        $this->httpClient = $httpClient;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws Exception
     */
    public function createESNcard(array $data): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pass = new PKPass();

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string_p12'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $serialNumber = 'esncard-' . $data['id'];

        $paidDate = new DateTime($data['date_paid']);
        $paidDate->setTime(0, 0);

        $passData = $this->getCommonAttributes($serialNumber) +
            [
                'description' => 'ESNcard',
                'logoText' => 'ESNcard',
                'backgroundColor' => 'rgb(46, 49, 146)',
                'serialNumber' => $serialNumber,
                'generic' => [
                    'primaryFields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name & Surname',
                            'value' => "{$data['name']} {$data['surname']}",
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'nationality',
                            'label' => 'Nationality',
                            'value' => $data['nationality'],
                        ],
                        [
                            'key' => 'dob',
                            'label' => 'Date of Birth',
                            'value' => (new DateTime($data['dob']))->format('d/m/Y')
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'studies_at',
                            'label' => 'Studies at',
                            'value' => $data['host_institution']
                        ],
                        [
                            'key' => 'esn_section',
                            'label' => 'ESN Section',
                            'value' => $moduleConfig->get('organization_name')
                        ],
                        [
                            'key' => 'valid_since',
                            'label' => 'Valid Since',
                            'value' => $paidDate->format('d/m/Y')
                        ]
                    ],
                    'backFields' => [
                        [
                            'key' => 'local_disclaimer',
                            'label' => 'Disclaimer',
                            'value' => 'This pass can only be used in local events.'
                        ]
                    ]
                ],
                'barcodes' => [
                    [
                        'format' => 'PKBarcodeFormatCode128',
                        'messageEncoding' => 'iso-8859-1',
                        'message' => $data['esncard_number'],
                        'altText' => $data['esncard_number'],
                    ]
                ],
            ];

        $pass->setData($passData);

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/color/';

        $type = $this->fileService->getFileMimeType($data['face_photo_fid']);
        if (!empty($type)) {
            if ($type == 'image/png') {
                $pass->addFile($this->fileService->getFilePath($data['face_photo_fid']), 'thumbnail.png');
            } else {
                $imageContents = $this->fileService->readFile($data['face_photo_fid']);

                if ($imageResource = imagecreatefromstring($imageContents)) {
                    ob_start();
                    imagepng($imageResource);
                    $pngData = ob_get_clean();

                    imagedestroy($imageResource);

                    if ($this->fileService->replaceFileData($data['face_photo_fid'], $pngData)) {
                        $pass->addFile($this->fileService->getFilePath($data['face_photo_fid']), 'thumbnail.png');
                    }
                }
            }
        }

        $pass->addFile($imagesPath . 'logo.png', 'logo.png');
        $pass->addFile($imagesPath . 'logo@2x.png', 'logo@2x.png');
        $pass->addFile($imagesPath . 'logo@3x.png', 'logo@3x.png');

        $pass->addFile($imagesPath . 'icon.png', 'icon.png');
        $pass->addFile($imagesPath . 'icon@2x.png', 'icon@2x.png');
        $pass->addFile($imagesPath . 'icon@3x.png', 'icon@3x.png');

        try {
            return $pass->create();
        } catch (PKPassException $e) {
            $this->logger->error('Apple Wallet Pass creation failed: ' . $e->getMessage());
            return NULL;
        }
    }

    protected function getCommonAttributes(string $serialNumber): array
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $siteSalt = $this->settings::getHashSalt();
        $authToken = hash('sha256', $serialNumber . $siteSalt);

        $apiRoot = preg_replace('/\/v1\/log\/?$/', '', Url::fromRoute('esn_membership_manager.apple_wallet_log', [], ['absolute' => TRUE])->toString());

        return [
            'formatVersion' => 1,
            'organizationName' => $moduleConfig->get('organization_name') ?? 'Erasmus Student Network',
            'teamIdentifier' => $moduleConfig->get('apple_team_id'),
            'passTypeIdentifier' => $moduleConfig->get('apple_pass_type_id'),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(255, 255, 255)',
            'webServiceURL' => $apiRoot,
            'authenticationToken' => $authToken,
        ];
    }

    /**
     * @throws Exception
     */
    public function createFreePass(array $data): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pass = new PKPass();

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string_p12'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $serialNumber = 'free_pass-' . $data['id'];

        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);

        $passData = $this->getCommonAttributes($serialNumber) +
            [
                'description' => $moduleConfig->get('scheme_name'),
                'logoText' => $moduleConfig->get('scheme_name'),
                'backgroundColor' => 'rgb(0, 174, 239)',
                'serialNumber' => $serialNumber,
                'generic' => [
                    'primaryFields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name & Surname',
                            'value' => "{$data['name']} {$data['surname']}",
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'nationality',
                            'label' => 'Nationality',
                            'value' => $data['nationality'],
                        ],
                        [
                            'key' => 'mobility_status',
                            'label' => 'Mobility Status',
                            'value' => $data['mobility_status']
                        ],
                        [
                            'key' => 'dob',
                            'label' => 'Date of Birth',
                            'value' => (new DateTime($data['dob']))->format('d/m/Y')
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'host_institution',
                            'label' => 'Host Institution',
                            'value' => $data['host_institution']
                        ],
                        [
                            'key' => 'valid_since',
                            'label' => 'Valid Since',
                            'value' => $approvedDate->format('d/m/Y')
                        ]
                    ],
                    'backFields' => [
                        [
                            'key' => 'local_disclaimer',
                            'label' => 'Disclaimer',
                            'value' => 'This pass can only be used in local events.'
                        ]
                    ]
                ],
                'barcodes' => [
                    [
                        'format' => 'PKBarcodeFormatQR',
                        'messageEncoding' => 'iso-8859-1',
                        'message' => $data['pass_token'],
                        'altText' => $data['pass_token'],
                    ]
                ],
            ];

        $pass->setData($passData);

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/white/';

        $pass->addFile($imagesPath . 'logo.png', 'logo.png');
        $pass->addFile($imagesPath . 'logo@2x.png', 'logo@2x.png');
        $pass->addFile($imagesPath . 'logo@3x.png', 'logo@3x.png');

        $pass->addFile($imagesPath . 'icon.png', 'icon.png');
        $pass->addFile($imagesPath . 'icon@2x.png', 'icon@2x.png');
        $pass->addFile($imagesPath . 'icon@3x.png', 'icon@3x.png');

        try {
            return $pass->create();
        } catch (PKPassException $e) {
            $this->logger->error('Apple Wallet Pass creation failed: ' . $e->getMessage());
            return NULL;
        }
    }

    /**
     * @throws Exception
     */
    public function createGuestPass(array $data): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pass = new PKPass();

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string_p12'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $serialNumber = 'guest-' . $data['id'];

        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P7D"));

        $passData = $this->getCommonAttributes($serialNumber) +
            [
                'description' => $moduleConfig->get('guest_scheme_name'),
                'logoText' => $moduleConfig->get('guest_scheme_name'),
                'backgroundColor' => 'rgb(236, 0, 140)',
                'serialNumber' => $serialNumber,
                'generic' => [
                    'primaryFields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name & Surname',
                            'value' => "{$data['name']} {$data['surname']}",
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'referer_name',
                            'label' => 'Referer Name',
                            'value' => "{$data['referer_name']} {$data['referer_surname']}",
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'referer_mobility_status',
                            'label' => 'Referer Mobility Status',
                            'value' => $data['referer_mobility_status']
                        ],
                        [
                            'key' => 'valid_until',
                            'label' => 'Valid Until',
                            'value' => $expiryDate->format('d/m/Y')
                        ]
                    ],
                    'backFields' => [
                        [
                            'key' => 'local_disclaimer',
                            'label' => 'Local Disclaimer',
                            'value' => 'This pass can only be used in local events.'
                        ],
                        [
                            'key' => 'guest_disclaimer',
                            'label' => 'Guest Disclaimer',
                            'value' => 'To redeem this pass you will need to present valid ID at the door as well as arrive at the venue with the person that invited you.'
                        ]
                    ]
                ],
                'barcodes' => [
                    [
                        'format' => 'PKBarcodeFormatAztec',
                        'messageEncoding' => 'iso-8859-1',
                        'message' => $data['guest_pass_token'],
                        'altText' => $data['guest_pass_token'],
                    ]
                ],
            ];

        $pass->setData($passData);

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/white/';

        $pass->addFile($imagesPath . 'logo.png', 'logo.png');
        $pass->addFile($imagesPath . 'logo@2x.png', 'logo@2x.png');
        $pass->addFile($imagesPath . 'logo@3x.png', 'logo@3x.png');

        $pass->addFile($imagesPath . 'icon.png', 'icon.png');
        $pass->addFile($imagesPath . 'icon@2x.png', 'icon@2x.png');
        $pass->addFile($imagesPath . 'icon@3x.png', 'icon@3x.png');

        try {
            return $pass->create();
        } catch (PKPassException $e) {
            $this->logger->error('Apple Wallet Pass creation failed: ' . $e->getMessage());
            return NULL;
        }
    }

    public function sendUpdateNotification(string $pushToken): bool
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pemString = $moduleConfig->get('apple_certificate_string_pem');
        $password = $moduleConfig->get('apple_certificate_password');

        $certificatePath = $this->fileService->getTemporaryFile('apns_cert_', '.pem');

        try {
            file_put_contents($certificatePath, $pemString);

            $this->httpClient->request('POST', "https://api.push.apple.com/3/device/{$pushToken}", [
                'version' => '2.0',
                'body' => '{}',
                'cert' => [$certificatePath, $password],
                'headers' => [
                    'apns-topic' => $moduleConfig->get('apple_pass_type_id'),
                ],
            ]);

            return true;
        } catch (GuzzleException $e) {
            $this->logger->error('APNs Push Failed for token @token: @error', ['@token' => $pushToken, '@error' => $e->getMessage(),]);

            if (method_exists($e, 'hasResponse') && $e->hasResponse()) {
                $responseBody = (string)$e->getResponse()->getBody();
                $statusCode = $e->getResponse()->getStatusCode();
                $this->logger->error('APNs Error Details (@code): @body', ['@code' => $statusCode, '@body' => $responseBody]);
            }

            return false;
        } finally {
            if (file_exists($certificatePath)) {
                unlink($certificatePath);
            }
        }
    }
}