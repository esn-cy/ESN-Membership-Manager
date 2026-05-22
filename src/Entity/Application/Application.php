<?php

namespace Drupal\esn_membership_manager\Entity\Application;

use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\EnumDrivenEntityBase;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\file\FileInterface;
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
class Application extends EnumDrivenEntityBase implements ApplicationInterface
{
    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public static function postDelete(EntityStorageInterface $storage, array $entities): void
    {
        parent::postDelete($storage, $entities);

        $moduleConfig = Drupal::config('esn_membership_manager.settings');
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
            if ($proof = $application->getProofDocument()) {
                $fileService->deleteFile($proof->id(), $application->id());
            }
            if ($idDocument = $application->getIDDocument()) {
                $fileService->deleteFile($idDocument->id(), $application->id());
            }
            if ($facePhoto = $application->getFacePhoto()) {
                $fileService->deleteFile($facePhoto->id(), $application->id());
            }
            if ($fileService->isDirectoryEmpty('membership://' . $application->id())) {
                $fileService->deleteDirectory('membership://' . $application->id());
            }

            if ($paymentLinkID = $application->getValue(ApplicationField::PaymentLinkID)) {
                $stripeService->disablePaymentLink($paymentLinkID);
            }

            if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                if ($application->getValue(ApplicationField::HasESNcard)) {
                    $googleService->deleteObject($application->id(), 'card');
                }
                $googleService->deleteObject($application->id(), 'pass');
            }

            if ($moduleConfig->get('switch_apple_wallet') ?? FALSE) {
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
    public function getProofDocument(): ?FileInterface
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