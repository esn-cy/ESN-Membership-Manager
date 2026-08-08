<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass;
use Exception;

class GuestPassService
{
    protected GuestPassStorage $guestPassStorage;
    protected MembershipSettings $membershipSettings;
    protected ApproveGuestPass $approveGuestPass;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws PluginException
     */
    public function __construct(
        EntityTypeManagerInterface $entityTypeManager,
        ConfigFactoryInterface     $configFactory,
        ActionManager              $actionManager,
    )
    {
        /** @var GuestPassStorage $guestPassStorage */
        $guestPassStorage = $entityTypeManager->getStorage('membership_guest');

        $membershipSettings = new MembershipSettings($configFactory);

        /** @var ApproveGuestPass $approveGuestPass */
        $approveGuestPass = $actionManager->createInstance('esn_membership_manager_approve_guest');

        $this->guestPassStorage = $guestPassStorage;
        $this->membershipSettings = $membershipSettings;
        $this->approveGuestPass = $approveGuestPass;
    }

    /**
     * @throws EntityStorageException
     * @throws Exception
     */
    public function requestGuestPass(Application $referrer, string $name, string $surname, string $email, string $reason): void
    {
        /** @var GuestPassInterface $guestPass */
        $guestPass = $this->guestPassStorage->create();
        $guestPass->setValue(GuestPassField::RefererID, $referrer->id());
        $guestPass->setValue(GuestPassField::Name, $name);
        $guestPass->setValue(GuestPassField::Surname, $surname);
        $guestPass->setValue(GuestPassField::Email, $email);
        $guestPass->setValue(GuestPassField::Reason, $reason);
        $guestPass->setValue(GuestPassField::DateCreated, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

        $specialMode = false;
        if (
            !empty($this->membershipSettings->getGuestPassSpecialMobilities()) &&
            in_array($referrer->getValue(ApplicationField::MobilityStatus), $this->membershipSettings->getGuestPassSpecialMobilities())
        ) {
            $interval = $this->membershipSettings->getGuestPassSpecialInterval();
            $perPersonLimit = $this->membershipSettings->getGuestPassSpecialPerPersonLimit();
            $concurrentLimit = $this->membershipSettings->getGuestPassSpecialConcurrentLimit();

            if (!empty($interval) && (!empty($perPersonLimit) || !empty($concurrentLimit))) {
                $specialMode = true;
            }
        }

        $automaticallyApproved = true;
        if ($specialMode) {
            if (!empty($perPersonLimit)) {
                $activePasses = $this->guestPassStorage->getActiveByReferrerID($referrer->id());
                if (count($activePasses) >= $perPersonLimit) {
                    $automaticallyApproved = false;
                }
            }

            if (!empty($concurrentLimit)) {
                $activePasses = $this->guestPassStorage->getActive($interval ?? 'P7D');

                $specialConcurrent = array_filter($activePasses, function ($activePass) {
                    $mobilityStatus = $activePass->getReferer()->getValue(ApplicationField::MobilityStatus);
                    return in_array($mobilityStatus, $this->membershipSettings->getGuestPassSpecialMobilities());
                });

                if (count($specialConcurrent) >= $concurrentLimit) {
                    $automaticallyApproved = false;
                }
            }
        } else {
            if ($this->guestPassStorage->countDuplicates($name, $surname, $email) > $this->membershipSettings->getGuestPassInstantLimit()) {
                $automaticallyApproved = false;
            }
        }

        $guestPass->save();

        if ($automaticallyApproved) {
            $this->approveGuestPass->execute($guestPass);
        }
    }
}