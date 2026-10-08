<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPass;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Form\ViewGuestPassesForm;
use Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;

/**
 * Unit tests for ViewGuestPassesForm.
 *
 * @covers \Drupal\esn_membership_manager\Form\ViewGuestPassesForm
 * @group esn_membership_manager
 */
class ViewGuestPassesFormTest extends MembershipManagerTestCase
{
    private GuestPassStorage $guestPassStorage;
    private MessengerInterface $messenger;
    private ViewGuestPassesForm $form;

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->guestPassStorage = $this->createMock(GuestPassStorage::class);
        $approveGuestPass = $this->createMock(ApproveGuestPass::class);
        $logger = $this->createMock(LoggerChannelInterface::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_guest')->willReturn($this->guestPassStorage);

        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->with('esn_membership_manager_approve_guest')->willReturn($approveGuestPass);

        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('plugin.manager.action', $actionManager);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($logger));
        $this->container->set('messenger', $this->messenger);

        $this->form = new ViewGuestPassesForm(
            $entityTypeManager,
            $actionManager,
            $this->getLoggerFactoryMock($logger)
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esn_membership_manager_view_guest_passes', $this->form->getFormId());
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $form = ViewGuestPassesForm::create($this->container);
        $this->assertInstanceOf(ViewGuestPassesForm::class, $form);
    }

    public function testFilterFormSubmitWithAllParams(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'search' => 'john',
            'status' => 'Pending',
            'sort_by' => 'created',
            'sort_order' => 'DESC',
        ]);

        $this->form->filterFormSubmit($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_guest_passes', $redirect->getRouteName());
        $this->assertEquals([
            'search' => 'john',
            'status' => 'Pending',
            'sort_by' => 'created',
            'sort_order' => 'DESC',
        ], $redirect->getOptions()['query']);
    }

    public function testFilterFormSubmitWithEmptyParams(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'search' => '',
            'status' => '',
            'sort_by' => '',
            'sort_order' => '',
        ]);

        $this->form->filterFormSubmit($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_guest_passes', $redirect->getRouteName());
        $this->assertEquals([], $redirect->getOptions()['query']);
    }

    public function testFilterFormReset(): void
    {
        $form = [];
        $formState = new FormState();

        $this->form->filterFormReset($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_guest_passes', $redirect->getRouteName());
        $this->assertEquals([], $redirect->getOptions()['query']);
    }

    public function testApproveGuestPassIgnoredWhenTriggerDoesNotMatch(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'other_button']);

        $this->guestPassStorage->expects($this->never())->method('load');
        $this->form->approveGuestPass($form, $formState);
        $this->assertFalse($formState->isRebuilding());
    }

    public function testApproveGuestPassWhenNotFound(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'approve_10']);

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('10')
            ->willReturn(null);

        $this->messenger->expects($this->never())->method('addStatus');
        $this->messenger->expects($this->never())->method('addError');

        $this->form->approveGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testApproveGuestPassSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'approve_10']);

        $guestPass = $this->createMock(GuestPass::class);
        $guestPass->expects($this->once())->method('delete');

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('10')
            ->willReturn($guestPass);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Guest Pass successfully approved.'));

        $this->form->approveGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testApproveGuestPassException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'approve_10']);

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('10')
            ->willThrowException(new Exception('Storage load failed'));

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Failed to approve Guest Pass: Storage load failed'));

        $this->form->approveGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testDeleteGuestPassIgnoredWhenTriggerDoesNotMatch(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'other_button']);

        $this->guestPassStorage->expects($this->never())->method('load');
        $this->form->deleteGuestPass($form, $formState);
        $this->assertFalse($formState->isRebuilding());
    }

    public function testDeleteGuestPassWhenNotFound(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'delete_20']);

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('20')
            ->willReturn(null);

        $this->messenger->expects($this->never())->method('addStatus');
        $this->messenger->expects($this->never())->method('addError');

        $this->form->deleteGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testDeleteGuestPassSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'delete_20']);

        $guestPass = $this->createMock(GuestPass::class);
        $guestPass->expects($this->once())->method('delete');

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('20')
            ->willReturn($guestPass);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Guest Pass successfully deleted.'));

        $this->form->deleteGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testDeleteGuestPassException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#name' => 'delete_20']);

        $this->guestPassStorage->expects($this->once())
            ->method('load')
            ->with('20')
            ->willThrowException(new Exception('Storage load error'));

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Failed to delete Guest Pass: Storage load error'));

        $this->form->deleteGuestPass($form, $formState);
        $this->assertTrue($formState->isRebuilding());
    }

    public function testSubmitForm(): void
    {
        $form = [];
        $formState = new FormState();
        $this->form->submitForm($form, $formState);
        $this->assertFalse($formState->hasAnyErrors());
    }
}
