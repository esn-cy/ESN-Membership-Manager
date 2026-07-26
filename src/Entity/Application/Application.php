<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\file\FileInterface;
use Drupal\omnia\Entity\EnumBackedEntityBase;
use Exception;

/**
 * @ContentEntityType(
 * id = "membership_application",
 * label = @Translation("Membership Application"),
 * base_table = "membership_application",
 * entity_keys = {
 *  "id" = "id",
 * },
 * handlers = {
 * "storage" = "Drupal\esn_membership_manager\Entity\Application\ApplicationStorage",
 * "access" = "Drupal\esn_membership_manager\Entity\Application\ApplicationAccess",
 * },
 * fieldable = TRUE,
 * )
 */
class Application extends EnumBackedEntityBase implements ApplicationInterface
{
    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public static function postDelete(EntityStorageInterface $storage, array $entities): void
    {
        parent::postDelete($storage, $entities);

        $membershipSettings = new MembershipSettings(Drupal::configFactory());
        $database = Drupal::database();
        /** @var LoggerChannelInterface $logger */
        $logger = Drupal::service('logger.factory')->get('esn_membership_manager');
        /** @var FileService $fileService */
        $fileService = Drupal::service('esn_membership_manager.file_service');
        /** @var StripeService $stripeService */
        $stripeService = Drupal::service('esn_membership_manager.stripe_service');
        /** @var GoogleService $googleService */
        $googleService = Drupal::service('esn_membership_manager.google_service');

        /** @var ApplicationInterface $application */
        foreach ($entities as $application) {
            if ($proof = $application->getStatusDocument()) {
                $fileService->deleteApplicationFile($proof->id(), $application->id());
            }
            if ($idDocument = $application->getIDDocument()) {
                $fileService->deleteApplicationFile($idDocument->id(), $application->id());
            }
            if ($facePhoto = $application->getFacePhoto()) {
                $fileService->deleteApplicationFile($facePhoto->id(), $application->id());
            }
            if ($fileService->isDirectoryEmpty('membership://' . $application->id())) {
                $fileService->deleteDirectory('membership://' . $application->id());
            }

            if ($paymentLinkID = $application->getValue(ApplicationField::PaymentLinkID)) {
                $stripeService->disablePaymentLink($paymentLinkID);
            }

            if ($membershipSettings->getGoogleWalletSwitch()) {
                if ($application->getValue(ApplicationField::HasESNcard)) {
                    $googleService->deleteApplicationObject($application->id(), 'card');
                }
                $googleService->deleteApplicationObject($application->id(), 'pass');
            }

            if ($membershipSettings->getAppleWalletSwitch()) {
                $database->delete('esn_membership_manager_apple_wallet_registrations')
                    ->condition('application_id', $application->id())
                    ->execute();
            }

            $database->delete('esn_membership_manager_authentication')
                ->condition('email', $application->getValue(ApplicationField::Email))
                ->execute();

            $logger->notice('Deleted application @id', ['@id' => $application->id()]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getStatusDocument(): ?FileInterface
    {
        return $this->getFile(ApplicationField::StatusProofFileID);
    }

    /**
     * {@inheritdoc}
     */
    public function getIDDocument(): ?FileInterface
    {
        return $this->getFile(ApplicationField::IdentityDocumentFileID);
    }

    /**
     * {@inheritdoc}
     */
    public function getFacePhoto(): ?FileInterface
    {
        return $this->getFile(ApplicationField::FacePhotoFileID);
    }

    protected static function getFieldEnumClass(): string
    {
        return ApplicationField::class;
    }

    /**
     * {@inheritdoc}
     */
    public function updateLastScanned(): self
    {
        $this->set(ApplicationField::DateLastScanned->value, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getFullName(): string
    {
        return "{$this->getValue(ApplicationField::Name)} {$this->getValue(ApplicationField::Surname)}";
    }

    private function getReasons(string $status): ?array
    {
        $reasons = [];
        if (str_contains($status, '-')) {
            $reasonsSplit = explode('/', $status);
            if (count($reasonsSplit) > 1) {
                foreach ($reasonsSplit as $reason) {
                    $reasonParts = explode('-', $reason);
                    if (count($reasonParts) != 3) {
                        continue;
                    }
                    $reasons[] = [
                        'status' => $reasonParts[0],
                        'category' => $reasonParts[1],
                        'issue' => $reasonParts[2],
                    ];
                }
            } else {
                $reasonParts = explode('-', $status);
                $reasons[] = [
                    'status' => $reasonParts[0],
                    'category' => $reasonParts[1],
                    'issue' => $reasonParts[2],
                ];
            }
        }

        return $reasons;
    }

    private function getDominantStatus(string $status): string
    {
        $successStatuses = ['Approved', 'Paid', 'Issued', 'Delivered', 'Blacklisted'];

        if (str_contains($status, '/')) {
            $reasons = $this->getReasons($status);

            $isPending = false;
            $successStatusIndex = -1;
            foreach ($reasons as $reason) {
                $currentStatus = $this->getDominantStatus($reason['status']);
                if ($currentStatus == 'Rejected') {
                    return $currentStatus;
                }
                if ($currentStatus == 'Pending') {
                    $isPending = true;
                    continue;
                }
                if ($successStatusIndex == -1) {
                    $successStatusIndex = array_search($currentStatus, $successStatuses);
                } elseif (($currentIndex = array_search($currentStatus, $successStatuses)) > $successStatusIndex) {
                    $successStatusIndex = $currentIndex;
                }
            }
            if ($isPending) {
                return 'Pending';
            } else {
                return $successStatuses[$successStatusIndex];
            }
        } else {
            preg_match('/^([a-zA-Z]+)/', $status, $matches);
            return $matches[1];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getApprovalStatus(): string
    {
        $rawStatus = $this->getValue(ApplicationField::ApprovalStatus);

        return $this->getDominantStatus($rawStatus);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllReasons(): ?array
    {
        $rawStatus = $this->getValue(ApplicationField::ApprovalStatus);

        return $this->getReasons($rawStatus);
    }

    /**
     * {@inheritdoc}
     */
    public function getPendingReasons(): ?array
    {
        $rawStatus = $this->getValue(ApplicationField::ApprovalStatus);
        if (!str_starts_with($rawStatus, 'Pending')) {
            return null;
        }

        $reasons = $this->getReasons($rawStatus);
        return array_filter($reasons, function ($reason) {
            return $reason['status'] == 'Pending';
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getRejectionReasons(): ?array
    {
        $rawStatus = $this->getValue(ApplicationField::ApprovalStatus);
        if (!str_starts_with($rawStatus, 'Rejected')) {
            return null;
        }

        $reasons = $this->getReasons($rawStatus);
        return array_filter($reasons, function ($reason) {
            return $reason['status'] == 'Rejected';
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getDateOfBirth(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DateOfBirth);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateCreated(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DateCreated);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateApproved(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DateApproved);
    }

    /**
     * {@inheritdoc}
     */
    public function getDatePaid(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DatePaid);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateLastScanned(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DateLastScanned);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateLastModified(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(ApplicationField::DateLastModified);
    }
}