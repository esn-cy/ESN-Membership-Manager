<?php

namespace Drupal\esn_membership_manager\Service;

use DateTime;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use PKPass\PKPass;
use PKPass\PKPassException;

class AppleWalletService
{
    protected ConfigFactoryInterface $configFactory;
    protected ModuleHandlerInterface $moduleHandler;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $config_factory,
        ModuleHandlerInterface        $moduleHandler,
        LoggerChannelFactoryInterface $logger_factory
    )
    {
        $this->configFactory = $config_factory;
        $this->moduleHandler = $moduleHandler;
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
}