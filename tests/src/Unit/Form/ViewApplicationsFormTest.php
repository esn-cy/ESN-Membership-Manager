<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Form\ViewApplicationsForm;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;

/**
 * Unit tests for ViewApplicationsForm.
 *
 * @covers \Drupal\esn_membership_manager\Form\ViewApplicationsForm
 * @group esn_membership_manager
 */
class ViewApplicationsFormTest extends MembershipManagerTestCase
{
    private ActionManager $actionManager;
    private ApplicationStorage $applicationStorage;
    private LoggerChannelInterface $logger;
    private MessengerInterface $messenger;
    private ViewApplicationsForm $form;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->actionManager = $this->createMock(ActionManager::class);
        $fileService = $this->createMock(FileService::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($this->applicationStorage);

        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('plugin.manager.action', $this->actionManager);
        $this->container->set('esn_membership_manager.file_service', $fileService);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($this->logger));
        $this->container->set('messenger', $this->messenger);

        $this->form = new ViewApplicationsForm(
            $entityTypeManager,
            $this->actionManager,
            $fileService,
            $this->getLoggerFactoryMock($this->logger)
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esn_membership_manager_view_applications', $this->form->getFormId());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $form = ViewApplicationsForm::create($this->container);
        $this->assertInstanceOf(ViewApplicationsForm::class, $form);
    }

    public function testGenerateFilePreviewNull(): void
    {
        $preview = $this->form->generateFilePreview(null);
        $this->assertEquals('', $preview);
    }

    public function testGenerateFilePreviewWithFile(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('id')->willReturn('123');

        $preview = $this->form->generateFilePreview($file);
        $this->assertIsArray($preview);
        $this->assertArrayHasKey('data', $preview);
        $this->assertEquals('link', $preview['data']['#type']);
        $this->assertEquals('123', $preview['data']['#url']->getRouteParameters()['file']);
    }

    public function testFilterFormSubmitWithAllParams(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'search' => 'jane',
            'status' => 'Approved',
            'esncard' => 1,
            'pass' => 1,
            'sort_by' => 'date_paid',
            'sort_order' => 'ASC',
        ]);

        $this->form->filterFormSubmit($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_applications', $redirect->getRouteName());
        $this->assertEquals([
            'search' => 'jane',
            'status' => 'Approved',
            'esncard' => 1,
            'pass' => 1,
            'sort_by' => 'date_paid',
            'sort_order' => 'ASC',
        ], $redirect->getOptions()['query']);
    }

    public function testFilterFormSubmitWithEmptyParams(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'search' => '',
            'status' => '',
            'esncard' => 0,
            'pass' => 0,
            'sort_by' => '',
            'sort_order' => '',
        ]);

        $this->form->filterFormSubmit($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_applications', $redirect->getRouteName());
        $this->assertEquals([], $redirect->getOptions()['query']);
    }

    public function testFilterFormReset(): void
    {
        $form = [];
        $formState = new FormState();

        $this->form->filterFormReset($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.view_applications', $redirect->getRouteName());
        $this->assertEquals([], $redirect->getOptions()['query']);
    }

    public function testSubmitFormIgnoredWhenTriggerIsFilter(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Filter']);

        $this->messenger->expects($this->never())->method('addWarning');
        $this->messenger->expects($this->never())->method('addError');
        $this->messenger->expects($this->never())->method('addStatus');

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormIgnoredWhenTriggerIsReset(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Reset']);

        $this->messenger->expects($this->never())->method('addWarning');
        $this->messenger->expects($this->never())->method('addError');
        $this->messenger->expects($this->never())->method('addStatus');

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormFacePdfAction(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', 'face_pdf');
        $formState->setValue('table', ['10' => '10', '20' => '20', '30' => 0]);

        $this->form->submitForm($form, $formState);

        $redirect = $formState->getRedirect();
        $this->assertNotNull($redirect);
        $this->assertEquals('esn_membership_manager.face_pdf', $redirect->getRouteName());
        $this->assertEquals(['id' => ['10', '20']], $redirect->getOptions()['query']);
    }

    public function testSubmitFormEmptySelectedIds(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', 'esn_membership_manager_approve');
        $formState->setValue('table', ['10' => 0, '20' => 0]);

        $this->messenger->expects($this->once())
            ->method('addWarning')
            ->with($this->matchesString('No items selected or no action chosen.'));

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormEmptyAction(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', '');
        $formState->setValue('table', ['10' => '10']);

        $this->messenger->expects($this->once())
            ->method('addWarning')
            ->with($this->matchesString('No items selected or no action chosen.'));

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormActionPluginNotFound(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', 'nonexistent_action');
        $formState->setValue('table', ['10' => '10']);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('nonexistent_action')
            ->willReturn(false);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Action plugin not found.'));

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', 'esn_membership_manager_approve');
        $formState->setValue('table', ['10' => '10', '20' => '20']);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $app10 = $this->createMock(Application::class);
        $app20 = $this->createMock(Application::class);

        $this->applicationStorage->expects($this->exactly(2))
            ->method('load')
            ->willReturnCallback(fn($id) => (string)$id === '10' ? $app10 : $app20);

        $action = $this->createMock(ActionBase::class);
        $action->expects($this->exactly(2))
            ->method('access')
            ->willReturnCallback(fn($id, $account) => (string)$id === '10');
        $action->expects($this->once())
            ->method('execute')
            ->with($app10);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willReturn($action);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Action applied to 2 items.'));

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setTriggeringElement(['#value' => 'Apply to selected items']);
        $formState->setValue('action', 'esn_membership_manager_approve');
        $formState->setValue('table', ['10' => '10']);

        $this->actionManager->expects($this->once())
            ->method('hasDefinition')
            ->with('esn_membership_manager_approve')
            ->willReturn(true);

        $this->actionManager->expects($this->once())
            ->method('createInstance')
            ->with('esn_membership_manager_approve')
            ->willThrowException(new Exception('Action creation failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to execute bulk action @action: @message',
                ['@action' => 'esn_membership_manager_approve', '@message' => 'Action creation failed']
            );

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('An error occurred while processing the action on ID : Action creation failed'));

        $this->form->submitForm($form, $formState);
    }
}
