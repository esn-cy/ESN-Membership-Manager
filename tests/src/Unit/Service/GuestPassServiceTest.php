<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass;
use Drupal\esn_membership_manager\Service\GuestPassService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * Tests for GuestPassService.
 *
 * @covers       \Drupal\esn_membership_manager\Service\GuestPassService
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class GuestPassServiceTest extends MembershipManagerTestCase
{
    /**
     * @throws EntityStorageException
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestGuestPassNormalModeApproved(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'guest_pass_instant_limit' => 2,
                'guest_pass_special_mobilities' => [],
            ],
        ]);

        $setValues = [];
        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->expects($this->exactly(6))
            ->method('setValue')
            ->willReturnCallback(function (GuestPassField $field, $value) use (&$setValues, $guestPass) {
                $setValues[$field->value] = $value;
                return $guestPass;
            });
        $guestPass->expects($this->once())->method('save');

        $guestPassStorage = $this->createMock(GuestPassStorage::class);
        $guestPassStorage->method('create')->willReturn($guestPass);
        $guestPassStorage->method('countDuplicates')
            ->with(self::TEST_TERTIARY_FIRST_NAME, self::TEST_TERTIARY_LAST_NAME, self::TEST_SECONDARY_EMAIL)
            ->willReturn(1); // 1 <= 2 limit -> approved!

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($guestPassStorage);

        $approveAction = $this->createMock(ApproveGuestPass::class);
        $approveAction->expects($this->once())->method('execute')->with($guestPass);

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')
            ->with('esn_membership_manager_approve_guest')
            ->willReturn($approveAction);

        $referrer = $this->createMock(Application::class);
        $referrer->method('id')->willReturn('10');
        $referrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Regular');

        $service = new GuestPassService($entityTypeManager, $configFactory, $actionManager);
        $service->requestGuestPass($referrer, self::TEST_TERTIARY_FIRST_NAME, self::TEST_TERTIARY_LAST_NAME, self::TEST_SECONDARY_EMAIL, 'Friend visiting');

        $this->assertSame('10', $setValues[GuestPassField::RefererID->value]);
        $this->assertSame(self::TEST_TERTIARY_FIRST_NAME, $setValues[GuestPassField::Name->value]);
        $this->assertSame(self::TEST_TERTIARY_LAST_NAME, $setValues[GuestPassField::Surname->value]);
        $this->assertSame(self::TEST_SECONDARY_EMAIL, $setValues[GuestPassField::Email->value]);
        $this->assertSame('Friend visiting', $setValues[GuestPassField::Reason->value]);
        $this->assertIsString($setValues[GuestPassField::DateCreated->value]);
    }

    /**
     * @throws EntityStorageException
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestGuestPassNormalModeDuplicateLimitExceeded(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'guest_pass_instant_limit' => 2,
                'guest_pass_special_mobilities' => [],
            ],
        ]);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->expects($this->once())->method('save');

        $guestPassStorage = $this->createMock(GuestPassStorage::class);
        $guestPassStorage->method('create')->willReturn($guestPass);
        $guestPassStorage->method('countDuplicates')
            ->with(self::TEST_TERTIARY_FIRST_NAME, self::TEST_TERTIARY_LAST_NAME, self::TEST_SECONDARY_EMAIL)
            ->willReturn(3); // 3 > 2 limit -> NOT automatically approved!

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($guestPassStorage);

        $approveAction = $this->createMock(ApproveGuestPass::class);
        $approveAction->expects($this->never())->method('execute');

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->willReturn($approveAction);

        $referrer = $this->createMock(Application::class);
        $referrer->method('id')->willReturn('10');
        $referrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Regular');

        $service = new GuestPassService($entityTypeManager, $configFactory, $actionManager);
        $service->requestGuestPass($referrer, self::TEST_TERTIARY_FIRST_NAME, self::TEST_TERTIARY_LAST_NAME, self::TEST_SECONDARY_EMAIL, 'Friend visiting');
    }

    /**
     * @throws EntityStorageException
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testRequestGuestPassSpecialModeApproved(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'guest_pass_special_mobilities' => ['Erasmus+ Studies'],
                'guest_pass_special_interval' => 'P7D',
                'guest_pass_special_per_person_limit' => 2,
                'guest_pass_special_concurrent_limit' => 5,
            ],
        ]);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->expects($this->once())->method('save');

        $guestPassStorage = $this->createMock(GuestPassStorage::class);
        $guestPassStorage->method('create')->willReturn($guestPass);
        // Active for referrer is 1 (< 2)
        $guestPassStorage->method('getActiveByReferrerID')->with('20')->willReturn([$this->createMock(GuestPassInterface::class)]);

        // Active concurrent: 1 active pass with special mobility (< 5)
        $otherReferrer = $this->createMock(ApplicationInterface::class);
        $otherReferrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');
        $otherActivePass = $this->createMock(GuestPassInterface::class);
        $otherActivePass->method('getReferer')->willReturn($otherReferrer);

        $guestPassStorage->method('getActive')->with('P7D')->willReturn([$otherActivePass]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($guestPassStorage);

        $approveAction = $this->createMock(ApproveGuestPass::class);
        $approveAction->expects($this->once())->method('execute')->with($guestPass);

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->willReturn($approveAction);

        $referrer = $this->createMock(Application::class);
        $referrer->method('id')->willReturn('20');
        $referrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');

        $service = new GuestPassService($entityTypeManager, $configFactory, $actionManager);
        $service->requestGuestPass($referrer, 'Bob', 'Jones', 'bob@example.com', 'Visiting campus');
    }

    /**
     * @throws EntityStorageException
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testRequestGuestPassSpecialModePerPersonLimitExceeded(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'guest_pass_special_mobilities' => ['Erasmus+ Studies'],
                'guest_pass_special_interval' => 'P7D',
                'guest_pass_special_per_person_limit' => 2,
                'guest_pass_special_concurrent_limit' => null,
            ],
        ]);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->expects($this->once())->method('save');

        $guestPassStorage = $this->createMock(GuestPassStorage::class);
        $guestPassStorage->method('create')->willReturn($guestPass);
        // Active for referrer is 2 (>= 2 limit)
        $guestPassStorage->method('getActiveByReferrerID')->with('20')->willReturn([
            $this->createMock(GuestPassInterface::class),
            $this->createMock(GuestPassInterface::class),
        ]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($guestPassStorage);

        $approveAction = $this->createMock(ApproveGuestPass::class);
        $approveAction->expects($this->never())->method('execute');

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->willReturn($approveAction);

        $referrer = $this->createMock(Application::class);
        $referrer->method('id')->willReturn('20');
        $referrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');

        $service = new GuestPassService($entityTypeManager, $configFactory, $actionManager);
        $service->requestGuestPass($referrer, 'Bob', 'Jones', 'bob@example.com', 'Visiting campus');
    }

    /**
     * @throws EntityStorageException
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testRequestGuestPassSpecialModeConcurrentLimitExceeded(): void
    {
        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'guest_pass_special_mobilities' => ['Erasmus+ Studies'],
                'guest_pass_special_interval' => 'P7D',
                'guest_pass_special_per_person_limit' => null,
                'guest_pass_special_concurrent_limit' => 2,
            ],
        ]);

        $guestPass = $this->createMock(GuestPassInterface::class);
        $guestPass->expects($this->once())->method('save');

        $refSpecial1 = $this->createMock(ApplicationInterface::class);
        $refSpecial1->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');
        $pass1 = $this->createMock(GuestPassInterface::class);
        $pass1->method('getReferer')->willReturn($refSpecial1);

        $refSpecial2 = $this->createMock(ApplicationInterface::class);
        $refSpecial2->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');
        $pass2 = $this->createMock(GuestPassInterface::class);
        $pass2->method('getReferer')->willReturn($refSpecial2);

        $guestPassStorage = $this->createMock(GuestPassStorage::class);
        $guestPassStorage->method('create')->willReturn($guestPass);
        // 2 active special passes already (>= 2 concurrent limit)
        $guestPassStorage->method('getActive')->with('P7D')->willReturn([$pass1, $pass2]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($guestPassStorage);

        $approveAction = $this->createMock(ApproveGuestPass::class);
        $approveAction->expects($this->never())->method('execute');

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->willReturn($approveAction);

        $referrer = $this->createMock(Application::class);
        $referrer->method('id')->willReturn('20');
        $referrer->method('getValue')->with(ApplicationField::MobilityStatus)->willReturn('Erasmus+ Studies');

        $service = new GuestPassService($entityTypeManager, $configFactory, $actionManager);
        $service->requestGuestPass($referrer, 'Bob', 'Jones', 'bob@example.com', 'Visiting campus');
    }
}
