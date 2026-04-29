<?php

namespace Drupal\esn_membership_manager\Service;

use DateInterval;
use DateTime;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Exception;
use PKPass\PKPass;
use PKPass\PKPassException;

class AppleWalletService
{
    protected ConfigFactoryInterface $configFactory;
    protected ModuleHandlerInterface $moduleHandler;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected FileSystemInterface $fileSystem;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $config_factory,
        ModuleHandlerInterface        $moduleHandler,
        EntityTypeManagerInterface $entityTypeManager,
        FileSystemInterface        $fileSystem,
        LoggerChannelFactoryInterface $logger_factory
    )
    {
        $this->configFactory = $config_factory;
        $this->moduleHandler = $moduleHandler;
        $this->entityTypeManager = $entityTypeManager;
        $this->fileSystem = $fileSystem;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    /**
     * @throws Exception
     */
    public function createESNcard(array $data): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pass = new PKPass();

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $paidDate = new DateTime($data['date_paid']);
        $paidDate->setTime(0, 0);

        $passData = $this->getCommonAttributes() +
            [
                'description' => 'ESNcard',
                'logoText' => 'ESNcard',
                'backgroundColor' => 'rgb(46, 49, 146)',
                'serialNumber' => $data['esncard_number'],
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

        $thumbnailPath = "membership://{$data['id']}/apple_face_photo.png";
        $targetPath = $this->fileSystem->realpath($thumbnailPath);

        if (!file_exists($thumbnailPath)) {
            /** @var FileInterface $faceFile */
            $faceFile = $this->entityTypeManager->getStorage('file')->load($data['face_photo_fid']);
            if ($faceFile) {
                $uri = $faceFile->getFileUri();
                $path = $this->fileSystem->realpath($uri);

                if (imagepng(imagecreatefromstring(file_get_contents($path)), $targetPath)) {
                    $pass->addFile($targetPath, 'thumbnail.png');
                }
            }
        } else {
            $pass->addFile($targetPath, 'thumbnail.png');
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

    protected function getCommonAttributes(): array
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        return [
            'formatVersion' => 1,
            'organizationName' => $moduleConfig->get('organization_name') ?? 'Erasmus Student Network',
            'teamIdentifier' => $moduleConfig->get('apple_team_id'),
            'passTypeIdentifier' => $moduleConfig->get('apple_pass_type_id'),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(255, 255, 255)',
        ];
    }

    /**
     * @throws Exception
     */
    public function createFreePass(array $data): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $pass = new PKPass();

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);

        $passData = $this->getCommonAttributes() +
            [
                'description' => $moduleConfig->get('scheme_name'),
                'logoText' => $moduleConfig->get('scheme_name'),
                'backgroundColor' => 'rgb(0, 174, 239)',
                'serialNumber' => $data['pass_token'],
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

        $pass->setCertificateString($moduleConfig->get('apple_certificate_string'));
        $pass->setCertificatePassword($moduleConfig->get('apple_certificate_password'));

        $approvedDate = new DateTime($data['date_approved']);
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P7D"));

        $passData = $this->getCommonAttributes() +
            [
                'description' => $moduleConfig->get('guest_scheme_name'),
                'logoText' => $moduleConfig->get('guest_scheme_name'),
                'backgroundColor' => 'rgb(236, 0, 140)',
                'serialNumber' => $data['guest_pass_token'],
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
}