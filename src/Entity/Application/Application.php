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
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
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

    /**
     * {@inheritdoc}
     */
    public function getApprovalStatus(): string
    {
        return ApprovalStatuses::getDominantStatus($this->getValue(ApplicationField::ApprovalStatus))->status;
    }

    public function addApprovalStatus(string $status): bool|string
    {
        $currentStatus = $this->getValue(ApplicationField::ApprovalStatus);

        if (
            in_array($status, ApprovalStatuses::PaidStatuses) &&
            !$this->getValue(ApplicationField::HasESNcard)
        ) {
            return 'This status cannot be applied as this application does not have an ESNcard.';
        }

        $issue = ApprovalStatuses::canAddStatus($currentStatus, $status);
        if (!empty($issue)) {
            return $issue;
        }

        $newStatus = ApprovalStatuses::addStatus($currentStatus, $status);
        $this->setValue(ApplicationField::ApprovalStatus, $newStatus);
        return true;
    }

    public function removeApprovalStatus(string $status): void
    {
        $currentStatus = $this->getValue(ApplicationField::ApprovalStatus);
        $newStatus = ApprovalStatuses::removeStatus($currentStatus, $status);

        $this->setValue(ApplicationField::ApprovalStatus, $newStatus);
    }

    /**
     * {@inheritdoc}
     */
    public function getAllReasons(): ?array
    {
        $rawStatus = $this->getValue(ApplicationField::ApprovalStatus);
        $statuses = ApprovalStatuses::getStatuses($rawStatus);
        return array_filter($statuses, function ($status) {
            return !empty($status->category) || !empty($status->issue);
        });    }

    /**
     * {@inheritdoc}
     */
    public function getPendingReasons(): ?array
    {
        $reasons = $this->getAllReasons();
        return array_filter($reasons, function ($reason) {
            return $reason->status === ApprovalStatuses::Pending;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function clearPendingStatuses(): void
    {
        $reasons = $this->getPendingReasons();
        if (!empty($reasons)) {
            foreach ($reasons as $reason) {
                $this->removeApprovalStatus($reason->toString());
            }
        }
        $this->removeApprovalStatus('Pending');
    }

    /**
     * {@inheritdoc}
     */
    public function getRejectionReasons(): ?array
    {
        $reasons = $this->getAllReasons();
        return array_filter($reasons, function ($reason) {
            return $reason->status === ApprovalStatuses::Rejected;
        });
    }

    public function isApproved(): bool
    {
        return in_array($this->getApprovalStatus(), ApprovalStatuses::PositiveStatuses);
    }

    public function isPaid(): bool
    {
        return in_array($this->getApprovalStatus(), ApprovalStatuses::PaidStatuses);
    }

    public function isRejected(): bool
    {
        return in_array($this->getApprovalStatus(), ApprovalStatuses::NegativeStatuses, true);
    }

    public function isPending(): bool
    {
        return in_array($this->getApprovalStatus(), ApprovalStatuses::PendingStatuses, true);
    }

    public function isBlacklisted(): bool
    {
        return $this->getApprovalStatus() == ApprovalStatuses::Blacklisted;
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