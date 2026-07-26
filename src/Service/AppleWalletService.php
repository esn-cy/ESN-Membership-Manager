<?php

namespace Drupal\esn_membership_manager\Service;

use DateInterval;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\omnia\Service\AppleServiceBase;
use Drupal\omnia\Service\FileServiceBase;
use Exception;
use GuzzleHttp\ClientInterface;

class AppleWalletService extends AppleServiceBase
{
    protected ConfigFactoryInterface $configFactory;
    protected Settings $settings;
    protected ModuleHandlerInterface $moduleHandler;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Settings                      $settings,
        ModuleHandlerInterface        $moduleHandler,
        FileServiceBase $fileService,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($fileService, $httpClient, $loggerFactory);
        $this->configFactory = $configFactory;
        $this->settings = $settings;
        $this->moduleHandler = $moduleHandler;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws Exception
     */
    public function createESNcard(ApplicationInterface $application): ?string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $certificateP12 = $membershipSettings->getAppleCertificateP12();
        $certificatePassword = $membershipSettings->getAppleCertificatePassword();

        $serialNumber = 'esncard-' . $application->id();

        $paidDate = $application->getDatePaid();
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
                            'value' => $application->getFullName(),
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'nationality',
                            'label' => 'Nationality',
                            'value' => $application->getValue(ApplicationField::Nationality),
                        ],
                        [
                            'key' => 'dob',
                            'label' => 'Date of Birth',
                            'value' => $application->getDateOfBirth()->format('d/m/Y')
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'studies_at',
                            'label' => 'Studies at',
                            'value' => $application->getValue(ApplicationField::HostInstitution)
                        ],
                        [
                            'key' => 'esn_section',
                            'label' => 'ESN Section',
                            'value' => $application->getValue(ApplicationField::Section)
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
                        'message' => $application->getValue(ApplicationField::ESNcardNumber),
                        'altText' => $application->getValue(ApplicationField::ESNcardNumber),
                    ]
                ],
            ];

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/color/';

        $facePhotoFileID = $application->getFacePhoto()->id();

        $type = $this->fileService->getFileMimeType($facePhotoFileID);
        if (!empty($type)) {
            if ($type == 'image/png') {
                $images[] = [$this->fileService->getFilePath($facePhotoFileID) => 'thumbnail.png'];
            } else {
                $imageContents = $this->fileService->readFile($facePhotoFileID);

                if ($imageResource = imagecreatefromstring($imageContents)) {
                    ob_start();
                    imagepng($imageResource);
                    $pngData = ob_get_clean();

                    imagedestroy($imageResource);

                    if ($this->fileService->replaceFileData($facePhotoFileID, $pngData)) {
                        $images[] = [$this->fileService->getFilePath($facePhotoFileID) => 'thumbnail.png'];
                    }
                }
            }
        }

        $images = [
            'logo.png' => $imagesPath . 'logo.png',
            'logo@2x.png' => $imagesPath . 'logo@2x.png',
            'logo@3x.png' => $imagesPath . 'logo@3x.png',
            'icon.png' => $imagesPath . 'icon.png',
            'icon@2x.png' => $imagesPath . 'icon@2x.png',
            'icon@3x.png' => $imagesPath . 'icon@3x.png'
        ];

        return $this->createPass($passData, $images, $certificateP12, $certificatePassword);
    }

    protected function getCommonAttributes(string $serialNumber): array
    {
        $omniaSettings = new OmniaSettings($this->configFactory);
        $membershipSettings = new MembershipSettings($this->configFactory);

        $siteSalt = $this->settings::getHashSalt();
        $authToken = hash('sha256', $serialNumber . $siteSalt);

        $apiRoot = preg_replace('/\/v1\/log\/?$/', '', Url::fromRoute('esn_membership_manager.apple_wallet_log', [], ['absolute' => TRUE])->toString());

        return [
            'formatVersion' => 1,
            'organizationName' => $omniaSettings->getOrganisationName(),
            'teamIdentifier' => $omniaSettings->getAppleTeamID(),
            'passTypeIdentifier' => $membershipSettings->getApplePassTypeID(),
            'foregroundColor' => 'rgb(255, 255, 255)',
            'labelColor' => 'rgb(255, 255, 255)',
            'webServiceURL' => $apiRoot,
            'authenticationToken' => $authToken,
        ];
    }

    /**
     * @throws Exception
     */
    public function createFreePass(ApplicationInterface $application): ?string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $certificateP12 = $membershipSettings->getAppleCertificateP12();
        $certificatePassword = $membershipSettings->getAppleCertificatePassword();

        $serialNumber = 'free_pass-' . $application->id();

        $approvedDate = $application->getDateApproved();
        $approvedDate->setTime(0, 0);

        $passData = $this->getCommonAttributes($serialNumber) +
            [
                'description' => $membershipSettings->getPassName(),
                'logoText' => $membershipSettings->getPassName(),
                'backgroundColor' => 'rgb(0, 174, 239)',
                'serialNumber' => $serialNumber,
                'generic' => [
                    'primaryFields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name & Surname',
                            'value' => $application->getFullName(),
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'nationality',
                            'label' => 'Nationality',
                            'value' => $application->getValue(ApplicationField::Nationality)
                        ],
                        [
                            'key' => 'mobility_status',
                            'label' => 'Mobility Status',
                            'value' => $application->getValue(ApplicationField::MobilityStatus)
                        ],
                        [
                            'key' => 'dob',
                            'label' => 'Date of Birth',
                            'value' => $application->getDateOfBirth()->format('d/m/Y')
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'host_institution',
                            'label' => 'Host Institution',
                            'value' => $application->getValue(ApplicationField::HostInstitution)
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
                        'message' => $application->getValue(ApplicationField::PassToken),
                        'altText' => $application->getValue(ApplicationField::PassToken),
                    ]
                ],
            ];

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/white/';

        $images = [
            'logo.png' => $imagesPath . 'logo.png',
            'logo@2x.png' => $imagesPath . 'logo@2x.png',
            'logo@3x.png' => $imagesPath . 'logo@3x.png',
            'icon.png' => $imagesPath . 'icon.png',
            'icon@2x.png' => $imagesPath . 'icon@2x.png',
            'icon@3x.png' => $imagesPath . 'icon@3x.png'
        ];

        return $this->createPass($passData, $images, $certificateP12, $certificatePassword);
    }

    /**
     * @throws Exception
     */
    public function createGuestPass(GuestPassInterface $guestPass, ApplicationInterface $referer): ?string
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        $certificateP12 = $membershipSettings->getAppleCertificateP12();
        $certificatePassword = $membershipSettings->getAppleCertificatePassword();

        $serialNumber = 'guest-' . $guestPass->id();

        $approvedDate = $guestPass->getDateApproved();
        $approvedDate->setTime(0, 0);
        $expiryDate = (clone $approvedDate)->add(new DateInterval("P7D"));

        $passData = $this->getCommonAttributes($serialNumber) +
            [
                'description' => $membershipSettings->getGuestPassName(),
                'logoText' => $membershipSettings->getGuestPassName(),
                'backgroundColor' => 'rgb(236, 0, 140)',
                'serialNumber' => $serialNumber,
                'generic' => [
                    'primaryFields' => [
                        [
                            'key' => 'name',
                            'label' => 'Name & Surname',
                            'value' => $guestPass->getFullName()
                        ]
                    ],
                    'secondaryFields' => [
                        [
                            'key' => 'referer_name',
                            'label' => 'Referer Name',
                            'value' => $referer->getFullName()
                        ]
                    ],
                    'auxiliaryFields' => [
                        [
                            'key' => 'referer_mobility_status',
                            'label' => 'Referer Mobility Status',
                            'value' => $referer->getValue(ApplicationField::MobilityStatus)
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
                        'message' => $guestPass->getValue(ApplicationField::PassToken),
                        'altText' => $guestPass->getValue(ApplicationField::PassToken),
                    ]
                ],
            ];

        $imagesPath = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/apple_wallet/white/';

        $images = [
            'logo.png' => $imagesPath . 'logo.png',
            'logo@2x.png' => $imagesPath . 'logo@2x.png',
            'logo@3x.png' => $imagesPath . 'logo@3x.png',
            'icon.png' => $imagesPath . 'icon.png',
            'icon@2x.png' => $imagesPath . 'icon@2x.png',
            'icon@3x.png' => $imagesPath . 'icon@3x.png'
        ];

        return $this->createPass($passData, $images, $certificateP12, $certificatePassword);
    }

    public function sendApplicationUpdateNotification(string $pushToken): bool
    {
        $membershipSettings = new MembershipSettings($this->configFactory);

        return $this->sendUpdateNotification(
            $pushToken,
            $membershipSettings->getApplePassTypeID(),
            $membershipSettings->getAppleCertificatePEM(),
            $membershipSettings->getAppleCertificatePassword()
        );
    }
}