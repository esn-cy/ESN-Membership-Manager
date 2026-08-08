<?php

namespace Drupal\esn_membership_manager\Entity\GuestPass;

use DateInterval;
use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\omnia\Entity\EnumBackedEntityBase;
use Exception;

/**
 * @ContentEntityType(
 * id = "membership_guest",
 * label = @Translation("Membership Guest Pass"),
 * base_table = "membership_guest",
 * entity_keys = {
 *  "id" = "id",
 * },
 * handlers = {
 * "storage" = "Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage",
 * "access" = "Drupal\esn_membership_manager\Entity\GuestPass\GuestPassAccess",
 * },
 * fieldable = TRUE,
 * )
 */
class GuestPass extends EnumBackedEntityBase implements GuestPassInterface
{
    protected static function getFieldEnumClass(): string
    {
        return GuestPassField::class;
    }

    /**
     * @throws Exception
     */
    public static function postDelete(EntityStorageInterface $storage, array $entities): void
    {
        parent::postDelete($storage, $entities);

        $membershipSettings = new MembershipSettings(Drupal::configFactory());
        /** @var LoggerChannelInterface $logger */
        $logger = Drupal::service('logger.factory')->get('esn_membership_manager');
        /** @var GoogleService $googleService */
        $googleService = Drupal::service('esn_membership_manager.google_service');

        /** @var GuestPassInterface $guestPass */
        foreach ($entities as $guestPass) {
            if ($membershipSettings->getGoogleWalletSwitch()) {
                $googleService->deleteApplicationObject($guestPass->id(), 'guest');
            }

            $logger->notice('Deleted application @id', ['@id' => $guestPass->id()]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getReferer(): ?ApplicationInterface
    {
        $item = $this->get(GuestPassField::RefererID->value);
        $entity = $item->isEmpty() ? null : $item->entity;
        if ($entity instanceof ApplicationInterface) {
            return $entity;
        } else {
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getFullName(): string
    {
        return "{$this->getValue(GuestPassField::Name)} {$this->getValue(GuestPassField::Surname)}";
    }

    /**
     * {@inheritdoc}
     */
    public function getApprovalStatus(string $interval = 'P7D'): string
    {
        $expiryThreshold = (new DrupalDateTime())->sub(new DateInterval($interval));

        if (!empty($this->getValue(GuestPassField::DateRedeemed))) {
            return 'Redeemed';
        }
        if (!empty($this->getValue(GuestPassField::DateApproved))) {
            if ($this->getDateApproved()->getTimestamp() > $expiryThreshold->getTimestamp()) {
                return 'Approved';
            } else {
                return 'Expired';
            }
        }
        return 'Pending';
    }

    /**
     * {@inheritdoc}
     */
    public function getDateCreated(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateCreated);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateApproved(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateApproved);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateRedeemed(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateRedeemed);
    }

    /**
     * {@inheritdoc}
     */
    public function getDateLastModified(): ?DrupalDateTime
    {
        return $this->getDateTimeValue(GuestPassField::DateLastModified);
    }
}