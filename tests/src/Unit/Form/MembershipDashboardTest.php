<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Form\AuthenticatedFormBase;
use Drupal\esn_membership_manager\Form\MembershipDashboard;
use Drupal\esn_membership_manager\Object\Status;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GuestPassService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\Client;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Unit tests for MembershipDashboard.
 *
 * @covers       \Drupal\esn_membership_manager\Form\MembershipDashboard
 * @uses         \Drupal\esn_membership_manager\Form\AuthenticatedFormBase
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\esn_membership_manager\Utility\Nationalities
 * @uses         \Drupal\esn_membership_manager\Object\Status
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class MembershipDashboardTest extends MembershipManagerTestCase
{
    private ApplicationStorage $applicationStorage;
    private GuestPassStorage $guestPassStorage;
    private LoggerChannelInterface $logger;
    private FileService $fileService;
    private GuestPassService $guestPassService;
    private MessengerInterface $messenger;
    private MembershipDashboard $form;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $database = $this->createMock(Connection::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->guestPassStorage = $this->createMock(GuestPassStorage::class);
        $httpClient = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
        $this->fileService = $this->createMock(FileService::class);
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $this->guestPassService = $this->createMock(GuestPassService::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [],
        ]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturnCallback(function ($type) {
            return match ($type) {
                'membership_application' => $this->applicationStorage,
                'membership_guest' => $this->guestPassStorage,
                default => null,
            };
        });

        $requestStack = new RequestStack();
        $requestStack->push(new Request());

        $this->container->set('database', $database);
        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('http_client', $httpClient);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($this->logger));
        $this->container->set('config.factory', $configFactory);
        $this->container->set('esn_membership_manager.file_service', $this->fileService);
        $this->container->set('module_handler', $moduleHandler);
        $this->container->set('esn_membership_manager.guest_pass_service', $this->guestPassService);
        $this->container->set('messenger', $this->messenger);
        $this->container->set('request_stack', $requestStack);

        $this->form = new MembershipDashboard(
            $database,
            $entityTypeManager,
            $httpClient,
            $this->getLoggerFactoryMock($this->logger),
            $configFactory,
            $this->fileService,
            $moduleHandler,
            $this->guestPassService
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
        $this->form->setRequestStack($requestStack);
    }

    private function setAuthenticatedEmail(string $email): void
    {
        $prop = new ReflectionProperty(AuthenticatedFormBase::class, 'authenticatedEmail');
        $prop->setValue($this->form, $email);
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esn_membership_manager_dashboard', $this->form->getFormId());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $form = MembershipDashboard::create($this->container);
        $this->assertInstanceOf(MembershipDashboard::class, $form);
    }

    /**
     * @throws ReflectionException
     */
    public function testProtectedGetters(): void
    {
        $getAuthType = new ReflectionMethod(MembershipDashboard::class, 'getAuthenticationType');
        $this->assertEquals('login', $getAuthType->invoke($this->form));

        $isAuthReq = new ReflectionMethod(MembershipDashboard::class, 'isAuthenticationRequired');
        $this->assertTrue($isAuthReq->invoke($this->form));

        $headerMarkup = new ReflectionMethod(MembershipDashboard::class, 'headerMarkup');
        $this->assertStringContainsString('Manage your Application', (string)$headerMarkup->invoke($this->form));
    }

    public function testProcessFileUploadAttributes(): void
    {
        $form = [];
        $formState = new FormState();

        $elementWithUpload = ['upload' => ['#attributes' => []]];
        $processed = $this->form->processFileUploadAttributes($elementWithUpload, $formState, $form);
        $this->assertEquals('image/jpeg, image/png, application/pdf', $processed['upload']['#attributes']['accept']);

        $elementWithoutUpload = ['other' => []];
        $processedNoUpload = $this->form->processFileUploadAttributes($elementWithoutUpload, $formState, $form);
        $this->assertEquals(['other' => []], $processedNoUpload);
    }

    public function testProcessPhotoUploadAttributes(): void
    {
        $form = [];
        $formState = new FormState();

        $elementWithUpload = ['upload' => ['#attributes' => []]];
        $processed = $this->form->processPhotoUploadAttributes($elementWithUpload, $formState, $form);
        $this->assertEquals('image/jpeg, image/png', $processed['upload']['#attributes']['accept']);

        $elementWithoutUpload = ['other' => []];
        $processedNoUpload = $this->form->processPhotoUploadAttributes($elementWithoutUpload, $formState, $form);
        $this->assertEquals(['other' => []], $processedNoUpload);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPersonalFieldsWhenApprovedIdentity(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->set('is_approved_identity', true);

        $this->applicationStorage->expects($this->never())->method('getByEmailAddress');
        $this->form->submitPersonalFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPersonalFieldsWhenNoApplication(): void
    {
        $form = [];
        $formState = new FormState();
        $this->setAuthenticatedEmail('unknown@example.com');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with('unknown@example.com')
            ->willReturn(null);

        $this->form->submitPersonalFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPersonalFieldsWithChanges(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'name' => 'Alice',
            'surname' => 'Smith',
            'nationality' => 'GR',
            'date_of_birth' => '2000-05-15',
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('getValue')->willReturnMap([
            [ApplicationField::Name, 'Bob'],
            [ApplicationField::Surname, 'Jones'],
            [ApplicationField::Nationality, 'CY'],
        ]);
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('1999-01-01'));

        $application->expects($this->exactly(4))->method('setValue');
        $application->expects($this->exactly(4))->method('addApprovalStatus');
        $application->expects($this->once())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with(self::TEST_SECONDARY_EMAIL)
            ->willReturn($application);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Your personal information has been updated.'));

        $this->form->submitPersonalFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPersonalFieldsWithoutChanges(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'name' => 'Alice',
            'surname' => 'Smith',
            'nationality' => 'CY',
            'date_of_birth' => '2000-05-15',
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('getValue')->willReturnMap([
            [ApplicationField::Name, 'Alice'],
            [ApplicationField::Surname, 'Smith'],
            [ApplicationField::Nationality, 'CY'],
        ]);
        $application->method('getDateOfBirth')->willReturn(new DrupalDateTime('2000-05-15'));

        $application->expects($this->never())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with(self::TEST_SECONDARY_EMAIL)
            ->willReturn($application);

        $this->messenger->expects($this->never())->method('addStatus');

        $this->form->submitPersonalFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitMobilityFieldsWhenApprovedStatus(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->set('is_approved_status', true);

        $this->applicationStorage->expects($this->never())->method('getByEmailAddress');
        $this->form->submitMobilityFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitMobilityFieldsWhenNoApplication(): void
    {
        $form = [];
        $formState = new FormState();
        $this->setAuthenticatedEmail('unknown@example.com');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with('unknown@example.com')
            ->willReturn(null);

        $this->form->submitMobilityFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitMobilityFieldsWithChanges(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'mobility_status' => 'Erasmus+ Studies',
            'host_institution' => 'University of Cyprus',
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('getValue')->willReturnMap([
            [ApplicationField::MobilityStatus, 'Old Status'],
            [ApplicationField::HostInstitution, 'Old Inst'],
        ]);

        $application->expects($this->exactly(2))->method('setValue');
        $application->expects($this->once())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with(self::TEST_SECONDARY_EMAIL)
            ->willReturn($application);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Your mobility information has been updated.'));

        $this->form->submitMobilityFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitMobilityFieldsWithoutChanges(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'mobility_status' => 'Same Status',
            'host_institution' => 'Same Inst',
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('getValue')->willReturnMap([
            [ApplicationField::MobilityStatus, 'Same Status'],
            [ApplicationField::HostInstitution, 'Same Inst'],
        ]);

        $application->expects($this->never())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with(self::TEST_SECONDARY_EMAIL)
            ->willReturn($application);

        $this->messenger->expects($this->never())->method('addStatus');

        $this->form->submitMobilityFields($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitIdentityFileEmptyUpload(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('identity_upload', []);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);
        $application = $this->createMock(Application::class);
        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->expects($this->never())->method('readFile');
        $this->form->submitIdentityFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitIdentityFileExistingFileSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('identity_upload', ['fid_99']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('old_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getIDDocument')->willReturn($existingDoc);
        $application->method('isRejected')->willReturn(false);
        $application->expects($this->atLeastOnce())->method('save');

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->expects($this->once())
            ->method('readFile')
            ->with('fid_99')
            ->willReturn('file_content_data');

        $this->fileService->expects($this->once())
            ->method('replaceFileData')
            ->with('old_file_1', 'file_content_data')
            ->willReturn(true);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Identity document uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitIdentityFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitIdentityFileExistingFileFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('identity_upload', ['fid_99']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('old_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getIDDocument')->willReturn($existingDoc);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('file_content_data');
        $this->fileService->method('replaceFileData')->willReturn(false);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Identity document upload failed. Please try again later.'));

        $this->form->submitIdentityFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitIdentityFileNewFileSuccessAndRejectedApplication(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('identity_upload', ['fid_100']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $negStatus = new Status(ApprovalStatuses::Rejected, 'Identity', 'Document unreadable');

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('42');
        $application->method('getIDDocument')->willReturn(null);
        $application->method('isRejected')->willReturn(true);
        $application->method('getAllReasons')->willReturn([$negStatus]);

        $application->expects($this->once())->method('setValue')->with(ApplicationField::IdentityDocumentFileID, 'new_file_id');
        $application->expects($this->once())->method('removeApprovalStatus')->with($negStatus->toString());
        $application->expects($this->once())->method('addApprovalStatus');
        $application->expects($this->atLeastOnce())->method('save');

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('file_content_data');
        $this->fileService->expects($this->once())
            ->method('createApplicationFile')
            ->with('file_content_data', 'membership://42', 'id_document', '42')
            ->willReturn('new_file_id');

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Identity document uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitIdentityFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitIdentityFileNewFileFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('identity_upload', ['fid_100']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('42');
        $application->method('getIDDocument')->willReturn(null);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('file_content_data');
        $this->fileService->method('createApplicationFile')->willReturn(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Identity document upload failed. Please try again later.'));

        $this->form->submitIdentityFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitStatusFileExistingSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('status_upload', ['fid_status']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('status_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getStatusDocument')->willReturn($existingDoc);
        $application->method('isRejected')->willReturn(false);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('replaceFileData')->willReturn(true);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Proof of status uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitStatusFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitStatusFileExistingFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('status_upload', ['fid_status']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('status_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getStatusDocument')->willReturn($existingDoc);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('replaceFileData')->willReturn(false);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Proof of status upload failed. Please try again later.'));

        $this->form->submitStatusFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitStatusFileNewSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('status_upload', ['fid_status_new']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('101');
        $application->method('getStatusDocument')->willReturn(null);
        $application->method('isRejected')->willReturn(false);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('createApplicationFile')->willReturn('new_status_fid');

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Proof of status uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitStatusFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitStatusFileNewFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('status_upload', ['fid_status_new']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('101');
        $application->method('getStatusDocument')->willReturn(null);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('createApplicationFile')->willReturn(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Proof of status upload failed. Please try again later.'));

        $this->form->submitStatusFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPhotoFileExistingSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('photo_upload', ['fid_photo']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('photo_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getFacePhoto')->willReturn($existingDoc);
        $application->method('isRejected')->willReturn(false);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('replaceFileData')->willReturn(true);

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Face photo uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitPhotoFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPhotoFileExistingFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('photo_upload', ['fid_photo']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $existingDoc = $this->createMock(FileInterface::class);
        $existingDoc->method('id')->willReturn('photo_file_1');

        $application = $this->createMock(Application::class);
        $application->method('getFacePhoto')->willReturn($existingDoc);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('replaceFileData')->willReturn(false);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Face photo upload failed. Please try again later.'));

        $this->form->submitPhotoFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPhotoFileNewSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('photo_upload', ['fid_photo_new']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('202');
        $application->method('getFacePhoto')->willReturn(null);
        $application->method('isRejected')->willReturn(false);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('createApplicationFile')->willReturn('new_photo_fid');

        $this->messenger->expects($this->once())
            ->method('addStatus')
            ->with($this->matchesString('Face photo uploaded successfully. Your application will be reviewed soon.'));

        $this->form->submitPhotoFile($form, $formState);
    }

    /**
     * @throws EntityStorageException
     */
    public function testSubmitPhotoFileNewFailure(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('photo_upload', ['fid_photo_new']);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);

        $application = $this->createMock(Application::class);
        $application->method('id')->willReturn('202');
        $application->method('getFacePhoto')->willReturn(null);

        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->fileService->method('readFile')->willReturn('content');
        $this->fileService->method('createApplicationFile')->willReturn(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Face photo upload failed. Please try again later.'));

        $this->form->submitPhotoFile($form, $formState);
    }

    public function testValidateGuestPassRequestAllEmpty(): void
    {
        $form = [];
        $formState = new FormState();

        $this->form->validateGuestPassRequest($form, $formState);

        $this->assertTrue($formState->hasAnyErrors());
        $errors = $formState->getErrors();
        $this->assertArrayHasKey('guest_name', $errors);
        $this->assertArrayHasKey('guest_surname', $errors);
        $this->assertArrayHasKey('guest_email', $errors);
        $this->assertArrayHasKey('reason', $errors);
        $this->assertArrayHasKey('checkbox_id', $errors);
        $this->assertArrayHasKey('checkbox_conduct', $errors);
    }

    public function testValidateGuestPassRequestAllFilled(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'guest_name' => 'John',
            'guest_surname' => 'Doe',
            'guest_email' => 'john@example.com',
            'reason' => 'Visiting friend',
            'checkbox_id' => 1,
            'checkbox_conduct' => 1,
        ]);

        $this->form->validateGuestPassRequest($form, $formState);
        $this->assertEmpty($formState->getErrors());
    }

    public function testSubmitGuestPassRequestMissingFields(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'guest_name' => 'John',
            'guest_surname' => 'Doe',
        ]);

        $this->guestPassService->expects($this->never())->method('requestGuestPass');
        $this->form->submitGuestPassRequest($form, $formState);
    }

    public function testSubmitGuestPassRequestSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'guest_name' => 'John',
            'guest_surname' => 'Doe',
            'guest_email' => 'john@example.com',
            'reason' => 'Visiting friend',
            'checkbox_id' => 1,
            'checkbox_conduct' => 1,
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);
        $application = $this->createMock(Application::class);
        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->guestPassService->expects($this->once())
            ->method('requestGuestPass')
            ->with($application, 'John', 'Doe', 'john@example.com', 'Visiting friend');

        $this->form->submitGuestPassRequest($form, $formState);
    }

    public function testSubmitGuestPassRequestException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'guest_name' => 'John',
            'guest_surname' => 'Doe',
            'guest_email' => 'john@example.com',
            'reason' => 'Visiting friend',
            'checkbox_id' => 1,
            'checkbox_conduct' => 1,
        ]);

        $this->setAuthenticatedEmail(self::TEST_SECONDARY_EMAIL);
        $application = $this->createMock(Application::class);
        $this->applicationStorage->method('getByEmailAddress')->willReturn($application);

        $this->guestPassService->expects($this->once())
            ->method('requestGuestPass')
            ->willThrowException(new Exception('Limit exceeded'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Could not request a Guest Pass. Limit exceeded');

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not request a Guest Pass. Please try again later.'));

        $this->form->submitGuestPassRequest($form, $formState);
    }

    public function testSubmitForm(): void
    {
        $form = [];
        $formState = new FormState();
        $this->form->submitForm($form, $formState);
        $this->assertEmpty($formState->getErrors());
    }
}
