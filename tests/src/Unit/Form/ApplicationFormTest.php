<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Delete;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Entity\EntityConstraintViolationList;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Form\ApplicationForm;
use Drupal\esn_membership_manager\Form\AuthenticatedFormBase;
use Drupal\esn_membership_manager\Mail\BothConfirmationEmail;
use Drupal\esn_membership_manager\Mail\PassConfirmationEmail;
use Drupal\esn_membership_manager\Plugin\Action\ApproveApplication;
use Drupal\esn_membership_manager\Service\DiditService;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\omnia\Config\OmniaSettings;
use Drupal\omnia\Service\EmailService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\Client;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Unit tests for ApplicationForm.
 *
 * @covers       \Drupal\esn_membership_manager\Form\ApplicationForm
 * @uses         \Drupal\esn_membership_manager\Form\AuthenticatedFormBase
 * @uses         \Drupal\esn_membership_manager\Config\MembershipSettings
 * @uses         \Drupal\omnia\Config\OmniaSettings
 * @uses         \Drupal\esn_membership_manager\Utility\Nationalities
 * @uses         \Drupal\esn_membership_manager\Utility\MobilityStatuses
 * @uses         \Drupal\esn_membership_manager\Utility\ApprovalStatuses
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @uses         \Drupal\esn_membership_manager\Mail\BothConfirmationEmail
 * @uses         \Drupal\esn_membership_manager\Mail\PassConfirmationEmail
 * @uses         \Drupal\esn_membership_manager\Mail\MembershipEmailBase
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApplicationFormTest extends MembershipManagerTestCase
{
    private Connection $database;
    private ApplicationStorage $applicationStorage;
    private EntityStorageInterface $organisationStorage;
    private LoggerChannelInterface $logger;
    private FileService $fileService;
    private EmailService $emailService;
    private ApproveApplication $approveApplication;
    private DiditService $diditService;
    private MessengerInterface $messenger;
    private Session $session;
    private ApplicationForm $form;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(Connection::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->organisationStorage = $this->createMock(EntityStorageInterface::class);
        $httpClient = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
        $this->fileService = $this->createMock(FileService::class);
        $this->emailService = $this->createMock(EmailService::class);
        $this->diditService = $this->createMock(DiditService::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $moduleExtension = $this->createMock(Extension::class);
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')->with('esn_membership_manager')->willReturn($moduleExtension);

        $this->approveApplication = $this->createMock(ApproveApplication::class);
        $actionManager = $this->createMock(ActionManager::class);
        $actionManager->method('createInstance')->with('esn_membership_manager_approve')->willReturn($this->approveApplication);

        $configFactory = $this->getConfigFactoryStub([
            MembershipSettings::CONFIG_NAME => [
                'pass_name' => 'Erasmus Pass',
            ],
            OmniaSettings::CONFIG_NAME => [],
        ]);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->willReturnCallback(function ($type) {
            return match ($type) {
                'membership_application' => $this->applicationStorage,
                'esn_organisation' => $this->organisationStorage,
                default => null,
            };
        });

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturnCallback(function ($route, $params = [], $options = []) {
            return 'https://example.com/' . $route;
        });

        $this->container->set('database', $this->database);
        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('http_client', $httpClient);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($this->logger));
        $this->container->set('config.factory', $configFactory);
        $this->container->set('esn_membership_manager.file_service', $this->fileService);
        $this->container->set('omnia.email_service', $this->emailService);
        $this->container->set('module_handler', $moduleHandler);
        $this->container->set('plugin.manager.action', $actionManager);
        $this->container->set('esn_membership_manager.didit_service', $this->diditService);
        $this->container->set('messenger', $this->messenger);
        $this->container->set('request_stack', $requestStack);
        $this->container->set('url_generator', $urlGenerator);

        $this->form = new ApplicationForm(
            $configFactory,
            $this->database,
            $entityTypeManager,
            $httpClient,
            $this->fileService,
            $this->emailService,
            $moduleHandler,
            $actionManager,
            $this->diditService,
            $this->getLoggerFactoryMock($this->logger),
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
        $this->form->setRequestStack($requestStack);
    }

    private function setAuthenticatedUser(?string $email, bool $authenticated = true): void
    {
        $refAuth = new ReflectionProperty(AuthenticatedFormBase::class, 'isAuthenticated');
        $refAuth->setValue($this->form, $authenticated);

        $refEmail = new ReflectionProperty(AuthenticatedFormBase::class, 'authenticatedEmail');
        $refEmail->setValue($this->form, $email);
    }

    private function mockDatabaseSelect(?array $applicationData, ?Exception $exception = null): void
    {
        $select = $this->createMock(SelectInterface::class);
        $select->method('fields')->willReturnSelf();
        $select->method('condition')->willReturnSelf();

        if ($exception !== null) {
            $select->method('execute')->willThrowException($exception);
        } else {
            $statement = $this->createMock(StatementInterface::class);
            $statement->method('fetchAssoc')->willReturn($applicationData);
            $select->method('execute')->willReturn($statement);
        }

        $this->database->method('select')
            ->with('esn_membership_manager_in_progress_applications', 'i')
            ->willReturn($select);
    }

    private function mockDatabaseDelete(?Exception $exception = null): void
    {
        $delete = $this->createMock(Delete::class);
        $delete->method('condition')->willReturnSelf();

        if ($exception !== null) {
            $delete->method('execute')->willThrowException($exception);
        } else {
            $delete->method('execute')->willReturn(1);
        }

        $this->database->method('delete')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($delete);
    }

    private function mockDatabaseUpdate(?Exception $exception = null): void
    {
        $update = $this->createMock(Update::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();

        if ($exception !== null) {
            $update->method('execute')->willThrowException($exception);
        } else {
            $update->method('execute')->willReturn(1);
        }

        $this->database->method('update')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($update);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $instance = ApplicationForm::create($this->container);
        $this->assertInstanceOf(ApplicationForm::class, $instance);
    }

    public function testGetFormId(): void
    {
        $this->assertEquals('esn_membership_manager_application_form', $this->form->getFormId());
    }

    /**
     * @throws ReflectionException
     */
    public function testGetAuthenticationType(): void
    {
        $method = new ReflectionMethod(ApplicationForm::class, 'getAuthenticationType');
        $this->assertEquals('register', $method->invoke($this->form));
    }

    /**
     * @throws ReflectionException
     */
    public function testIsAuthenticationRequired(): void
    {
        $method = new ReflectionMethod(ApplicationForm::class, 'isAuthenticationRequired');
        $this->assertTrue($method->invoke($this->form));
    }

    /**
     * @throws ReflectionException
     */
    public function testHeaderMarkup(): void
    {
        $method = new ReflectionMethod(ApplicationForm::class, 'headerMarkup');
        $result = $method->invoke($this->form);
        $this->assertInstanceOf(MarkupInterface::class, $result);
        $this->assertStringContainsString('Apply for an ESNcard / Erasmus Pass', (string)$result);
    }

    public function testRedirectToDiditWithNoExistingToken(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', []);

        $this->diditService->expects($this->once())
            ->method('createVerificationSession')
            ->with(self::TEST_EMAIL)
            ->willReturn('https://verify.didit.me/session/new123');

        $form = [];
        $formState = new FormState();
        $response = $this->form->redirectToDidit($form, $formState);

        $this->assertInstanceOf(AjaxResponse::class, $response);
        $commands = $response->getCommands();
        $this->assertCount(1, $commands);
        $this->assertEquals('redirect', $commands[0]['command']);
        $this->assertEquals('https://verify.didit.me/session/new123', $commands[0]['url']);
    }

    public function testRedirectToDiditWithExistingToken(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'id_verification_token' => 'existing-token-xyz',
        ]);

        $this->diditService->expects($this->never())->method('createVerificationSession');

        $form = [];
        $formState = new FormState();
        $response = $this->form->redirectToDidit($form, $formState);

        $this->assertInstanceOf(AjaxResponse::class, $response);
        $commands = $response->getCommands();
        $this->assertCount(1, $commands);
        $this->assertEquals('redirect', $commands[0]['command']);
        $this->assertEquals('https://verify.didit.me/session/existing-token-xyz', $commands[0]['url']);
    }

    public function testRedirectToESNAccountsSuccess(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseUpdate();

        $form = [];
        $formState = new FormState();
        $response = $this->form->redirectToESNAccounts($form, $formState);

        $this->assertInstanceOf(AjaxResponse::class, $response);
        $commands = $response->getCommands();
        $this->assertCount(1, $commands);
        $this->assertEquals('redirect', $commands[0]['command']);
        $this->assertStringStartsWith('https://accounts.esn.org/cas/login?service=', $commands[0]['url']);
        $this->assertStringContainsString('token%3D', $commands[0]['url']);
    }

    public function testRedirectToESNAccountsException(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseUpdate(new Exception('Database lock error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to redirect to ESN Accounts: @message',
                ['@message' => 'Database lock error']
            );

        $form = [];
        $formState = new FormState();
        $response = $this->form->redirectToESNAccounts($form, $formState);

        $this->assertInstanceOf(AjaxResponse::class, $response);
        $this->assertEmpty($response->getCommands());
    }

    public function testValidateFormNotAuthenticated(): void
    {
        $this->setAuthenticatedUser(null, false);

        $form = [];
        $formState = new FormState();
        $this->form->validateForm($form, $formState);

        $this->assertFalse($formState->hasAnyErrors());
    }

    public function testValidateFormMissingProofOfStatus(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'status_verified' => false,
            'id_verified' => false,
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'proof_of_status' => null,
            'has_esncard' => false,
        ]);

        $this->form->validateForm($form, $formState);

        $errors = $formState->getErrors();
        $this->assertArrayHasKey('proof_of_status', $errors);
        $this->assertThat(
            $errors['proof_of_status'],
            $this->matchesString('Proof of status is missing. Please select your status again and re-upload the file.')
        );
        // Early return ensures id_document error is not yet evaluated
        $this->assertArrayNotHasKey('id_document', $errors);
    }

    public function testValidateFormMissingIdDocument(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'status_verified' => true,
            'id_verified' => false,
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'id_document' => null,
            'has_esncard' => false,
        ]);

        $this->form->validateForm($form, $formState);

        $errors = $formState->getErrors();
        $this->assertArrayHasKey('id_document', $errors);
        $this->assertThat(
            $errors['id_document'],
            $this->matchesString('ID Document is missing. Please upload your ID document to proceed.')
        );
    }

    public function testValidateFormMissingFacePhotoWhenHasESNcard(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'status_verified' => true,
            'id_verified' => true,
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => true,
            'face_photo' => null,
        ]);

        $this->form->validateForm($form, $formState);

        $errors = $formState->getErrors();
        $this->assertArrayHasKey('face_photo', $errors);
        $this->assertThat(
            $errors['face_photo'],
            $this->matchesString('A passport style photo is required for the ESNcard.')
        );
    }

    public function testValidateFormMissingBothIdDocumentAndFacePhoto(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'status_verified' => false,
            'id_verified' => false,
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'proof_of_status' => [101],
            'id_document' => null,
            'has_esncard' => true,
            'face_photo' => null,
        ]);

        $this->form->validateForm($form, $formState);

        $errors = $formState->getErrors();
        $this->assertArrayNotHasKey('proof_of_status', $errors);
        $this->assertArrayHasKey('id_document', $errors);
        $this->assertArrayHasKey('face_photo', $errors);
    }

    public function testValidateFormAllValid(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_verification_data', [
            'status_verified' => true,
            'id_verified' => true,
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => true,
            'face_photo' => [202],
        ]);

        $this->form->validateForm($form, $formState);

        $this->assertFalse($formState->hasAnyErrors());
    }

    public function testSubmitFormDatabaseSelectException(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect(null, new Exception('Query timeout'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unable to create in progress application. @error.',
                ['@error' => 'Query timeout']
            );

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('An error occurred fetching your application. Please try again.'));

        $form = [];
        $formState = new FormState();
        $formState->setValues(['has_esncard' => false]);

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormFileSaveFailureRollback(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect([
            'id' => 1,
            'didit_status' => 'Pending',
            'esn_status' => 'Pending',
        ]);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => true,
            'proof_of_status' => [10],
            'id_document' => [20],
            'face_photo' => [30],
        ]);

        $this->fileService->expects($this->exactly(3))
            ->method('saveApplicationFile')
            ->willReturnCallback(function ($fid, $appId) {
                // First file succeeds, second fails, third succeeds
                if ($fid === 10) {
                    return true;
                }
                if ($fid === 20) {
                    return false;
                }
                return true;
            });

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('An error occurred while saving your files. Please try again.'));

        // Rollback should delete only saved files (proof_of_status and face_photo)
        $deletedFids = [];
        $this->fileService->expects($this->exactly(2))
            ->method('deleteApplicationFile')
            ->willReturnCallback(function ($fid, $appId) use (&$deletedFids) {
                $deletedFids[] = $fid;
                $this->assertNull($appId);
                return true;
            });

        $this->form->submitForm($form, $formState);

        $this->assertEquals([10, 30], $deletedFids);
    }

    public function testSubmitFormInvalidDobException(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect([
            'id' => 1,
            'didit_status' => 'Pending',
            'esn_status' => 'Pending',
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Your selected date of birth is invalid. Please try again.'));

        // Inject language_manager that throws in getCurrentLanguage() during DrupalDateTime construction
        $languageManager = $this->createMock(LanguageManagerInterface::class);
        $languageManager->method('getCurrentLanguage')->willThrowException(new Exception('Date error'));
        $this->container->set('language_manager', $languageManager);

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'proof_of_status' => [10],
            'id_document' => [20],
            'dob' => '2000-01-01',
            'name' => 'John',
            'surname' => 'Doe',
            'nationality' => 'Greek',
            'section' => 'ESN Nicosia',
            'status' => 'erasmus_study',
            'host' => 'University of Cyprus',
        ]);

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormValidationViolations(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect([
            'id' => 1,
            'didit_status' => 'Pending',
            'esn_status' => 'Pending',
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $violation = $this->createMock(ConstraintViolationInterface::class);
        $violation->method('getMessage')->willReturn('Invalid email format');

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity, [$violation]);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->expects($this->never())->method('save');

        $this->applicationStorage->expects($this->once())
            ->method('create')
            ->willReturn($applicationEntity);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Validation failed: Invalid email format'));

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'proof_of_status' => [10],
            'id_document' => [20],
            'dob' => '2000-01-01',
            'name' => 'John',
            'surname' => 'Doe',
            'nationality' => 'Greek',
            'section' => 'ESN Nicosia',
            'status' => 'erasmus_study',
            'host' => 'University of Cyprus',
        ]);

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormApplicationSaveExceptionRollbackAllFiles(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect([
            'id' => 1,
            'didit_status' => 'Pending',
            'esn_status' => 'Pending',
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('save')->willThrowException(new Exception('Database disk full'));
        $applicationEntity->method('id')->willReturn('55');

        $this->applicationStorage->method('create')->willReturn($applicationEntity);

        // All 3 files should be deleted during rollback because status is unverified and has_esncard is true
        $deletedFids = [];
        $this->fileService->expects($this->exactly(3))
            ->method('deleteApplicationFile')
            ->willReturnCallback(function ($fid, $appId) use (&$deletedFids) {
                $deletedFids[] = $fid;
                $this->assertEquals('55', $appId);
                return true;
            });

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Error saving application. Please try again.'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Database disk full');

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => true,
            'proof_of_status' => [10],
            'id_document' => [20],
            'face_photo' => [30],
            'dob' => '2000-01-01',
            'name' => 'John',
            'surname' => 'Doe',
            'nationality' => 'Greek',
            'section' => 'ESN Nicosia',
            'status' => 'erasmus_study',
            'host' => 'University of Cyprus',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals([10, 20, 30], $deletedFids);
    }

    public function testSubmitFormApplicationSaveExceptionRollbackVerifiedStatusAndNoESNcard(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->mockDatabaseSelect([
            'id' => 1,
            'didit_status' => 'Pending',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Study',
            'status_host_institution' => 'University of Cyprus',
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('save')->willThrowException(new Exception('Entity constraint error'));
        $applicationEntity->method('id')->willReturn('77');

        $this->applicationStorage->method('create')->willReturn($applicationEntity);

        // Since status is verified and has_esncard is false, only id_document should be deleted
        $this->fileService->expects($this->once())
            ->method('deleteApplicationFile')
            ->with(20, '77');

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Error saving application. Please try again.'));

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'id_document' => [20],
            'dob' => '2000-01-01',
            'name' => 'Jane',
            'surname' => 'Doe',
            'nationality' => 'Cypriot',
            'section' => 'ESN Nicosia',
        ]);

        $this->form->submitForm($form, $formState);
    }

    public function testSubmitFormAutoApproveFlowSuccess(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('register_email_authentication_data', ['token' => 'abc']);
        $this->session->set('application_form_saved_data', ['name' => 'John']);
        $this->session->set('application_form_verification_data', ['id_verified' => true]);

        $this->mockDatabaseSelect([
            'id' => 42,
            'didit_status' => 'Approved',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Study',
            'status_host_institution' => 'University of Cyprus',
            'id_name' => 'John',
            'id_surname' => 'Doe',
            'id_nationality' => 'Cypriot',
            'id_dob' => '2001-05-20',
            'didit_session_id' => 'didit_sess_999',
        ]);

        $this->diditService->expects($this->once())
            ->method('getPDF')
            ->with('didit_sess_999')
            ->willReturn('pdf-content-binary');

        $this->fileService->expects($this->once())
            ->method('createApplicationFile')
            ->with('pdf-content-binary', 'membership://temp_uploads', 'id_document_42', null)
            ->willReturn('888');

        $this->fileService->expects($this->once())
            ->method('moveFile')
            ->with('888', 'membership://100', 'id_document')
            ->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('save');
        $applicationEntity->method('id')->willReturn('100');

        $this->applicationStorage->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields[ApplicationField::Name->value] === 'John'
                    && $fields[ApplicationField::HasVerifiedID->value] === 1
                    && $fields[ApplicationField::HasVerifiedStatus->value] === 1
                    && $fields[ApplicationField::IdentityDocumentFileID->value] === '888'
                    && !isset($fields['esncard']);
            }))
            ->willReturn($applicationEntity);

        $this->approveApplication->expects($this->once())
            ->method('execute')
            ->with($applicationEntity);

        $this->emailService->expects($this->never())->method('send');

        $this->diditService->expects($this->once())
            ->method('deleteSession')
            ->with('didit_sess_999');

        $this->mockDatabaseDelete();

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'section' => 'ESN Nicosia',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertNull($this->session->get('register_email_authentication_data'));
        $this->assertNull($this->session->get('application_form_saved_data'));
        $this->assertNull($this->session->get('application_form_verification_data'));

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormAutoApproveFlowApprovalExceptionHandled(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);

        $this->mockDatabaseSelect([
            'id' => 42,
            'didit_status' => 'Approved',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Study',
            'status_host_institution' => 'University of Cyprus',
            'id_name' => 'John',
            'id_surname' => 'Doe',
            'id_nationality' => 'Cypriot',
            'id_dob' => '2001-05-20',
            'didit_session_id' => 'didit_sess_999',
        ]);

        $this->diditService->method('getPDF')->willReturn('pdf-bytes');
        $this->fileService->method('createApplicationFile')->willReturn('888');

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('id')->willReturn('100');

        $this->applicationStorage->method('create')->willReturn($applicationEntity);

        // Approval throws exception, which must be caught silently
        $this->approveApplication->expects($this->once())
            ->method('execute')
            ->willThrowException(new Exception('Stripe API error'));

        $this->mockDatabaseDelete();

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'section' => 'ESN Nicosia',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormWithESNcardSendsBothConfirmationEmail(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);
        $this->session->set('application_form_saved_data', [
            'name' => 'Verified John',
        ]);

        $this->mockDatabaseSelect([
            'id' => 50,
            'didit_status' => 'Approved',
            'esn_status' => 'Pending',
            'id_name' => 'Verified John',
            'id_surname' => 'Doe',
            'id_nationality' => 'Spanish',
            'id_dob' => '1998-04-12',
            'didit_session_id' => 'didit_sess_50',
        ]);

        // filesExpected: proof_of_status and face_photo (id_document is verified so handled via didit)
        $this->fileService->expects($this->exactly(4))
            ->method('saveApplicationFile')
            ->willReturn(true);

        $this->diditService->method('getPDF')->with('didit_sess_50')->willReturn('pdf-bytes');
        $this->fileService->method('createApplicationFile')->willReturn('500');

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('id')->willReturn('200');

        $this->applicationStorage->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields[ApplicationField::Name->value] === 'Verified John'
                    && $fields[ApplicationField::HasVerifiedID->value] === 1
                    && $fields[ApplicationField::HasVerifiedStatus->value] === 0
                    && $fields[ApplicationField::StatusProofFileID->value] === 11
                    && $fields[ApplicationField::IdentityDocumentFileID->value] === '500'
                    && $fields[ApplicationField::FacePhotoFileID->value] === 33
                    && $fields['esncard'] === 1;
            }))
            ->willReturn($applicationEntity);

        // Move files should be called for status, id_document, and face_photo
        $movedFiles = [];
        $this->fileService->expects($this->exactly(3))
            ->method('moveFile')
            ->willReturnCallback(function ($fid, $target, $type) use (&$movedFiles) {
                $movedFiles[] = [$fid, $target, $type];
                return true;
            });

        $this->emailService->expects($this->once())
            ->method('send')
            ->with(
                self::TEST_EMAIL,
                $this->isInstanceOf(BothConfirmationEmail::class)
            );

        $this->diditService->expects($this->once())
            ->method('deleteSession')
            ->with('didit_sess_50');

        $this->mockDatabaseDelete();

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => true,
            'proof_of_status' => [11],
            'face_photo' => [33],
            'section' => 'ESN Nicosia',
            'status' => 'erasmus_study',
            'host' => 'University of Cyprus',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals([
            [11, 'membership://200', 'status'],
            ['500', 'membership://200', 'id_document'],
            [33, 'membership://200', 'face_photo'],
        ], $movedFiles);

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormWithoutESNcardSendsPassConfirmationEmail(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);

        $this->mockDatabaseSelect([
            'id' => 60,
            'didit_status' => 'Pending',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Internship',
            'status_host_institution' => 'Tech Corp',
            'didit_session_id' => null, // empty session ID ensures deleteSession is skipped
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('id')->willReturn('300');

        $this->applicationStorage->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                return $fields[ApplicationField::Name->value] === 'Manual Alice'
                    && $fields[ApplicationField::HasVerifiedID->value] === 0
                    && $fields[ApplicationField::HasVerifiedStatus->value] === 1
                    && $fields[ApplicationField::IdentityDocumentFileID->value] === 22
                    && !isset($fields[ApplicationField::StatusProofFileID->value])
                    && !isset($fields['esncard']);
            }))
            ->willReturn($applicationEntity);

        $this->diditService->expects($this->never())->method('deleteSession');

        $this->emailService->expects($this->once())
            ->method('send')
            ->with(
                self::TEST_EMAIL,
                $this->isInstanceOf(PassConfirmationEmail::class)
            );

        $this->mockDatabaseDelete();

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'id_document' => [22],
            'dob' => '2002-11-10',
            'name' => 'Manual Alice',
            'surname' => 'Smith',
            'nationality' => 'French',
            'section' => 'ESN Nicosia',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormDatabaseDeleteExceptionHandled(): void
    {
        $this->setAuthenticatedUser(self::TEST_EMAIL);

        $this->mockDatabaseSelect([
            'id' => 70,
            'didit_status' => 'Pending',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Internship',
            'status_host_institution' => 'Tech Corp',
            'didit_session_id' => null,
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('id')->willReturn('400');

        $this->applicationStorage->method('create')->willReturn($applicationEntity);

        // Delete throws exception
        $this->mockDatabaseDelete(new Exception('Delete in progress record failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unable to delete in progress application. @error',
                ['@error' => 'Delete in progress record failed']
            );

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'has_esncard' => false,
            'id_document' => [22],
            'dob' => '2002-11-10',
            'name' => 'Alice',
            'surname' => 'Smith',
            'nationality' => 'French',
            'section' => 'ESN Nicosia',
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testSubmitFormFallbackEmailAndSection(): void
    {
        // Unauthenticated email property is null, should fallback to form_state 'email'
        $this->setAuthenticatedUser(null);

        $this->mockDatabaseSelect([
            'id' => 80,
            'didit_status' => 'Pending',
            'esn_status' => 'Success',
            'status_mobility' => 'Erasmus+ Study',
            'status_host_institution' => 'Cyprus Univ',
            'didit_session_id' => null,
        ]);

        $this->fileService->method('saveApplicationFile')->willReturn(true);

        $applicationEntity = $this->createMock(Application::class);
        $violations = new EntityConstraintViolationList($applicationEntity);
        $applicationEntity->method('validate')->willReturn($violations);
        $applicationEntity->method('id')->willReturn('500');

        $this->applicationStorage->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $fields) {
                // Email is normalized lowercase trimmed, and section falls back to 'Unknown Section'
                return $fields[ApplicationField::Email->value] === 'fallback@example.com'
                    && $fields[ApplicationField::Section->value] === 'Unknown Section';
            }))
            ->willReturn($applicationEntity);

        $this->mockDatabaseDelete();

        $form = [];
        $formState = new FormState();
        $formState->setValues([
            'email' => '   FALLBACK@EXAMPLE.COM   ',
            'has_esncard' => false,
            'id_document' => [25],
            'dob' => '2000-02-02',
            'name' => 'Fallback',
            'surname' => 'User',
            'nationality' => 'Italian',
            'section' => null,
        ]);

        $this->form->submitForm($form, $formState);

        $this->assertEquals('esn_membership_manager.apply_success', $formState->getRedirect()->getRouteName());
    }

    public function testMobilityAjaxCallback(): void
    {
        $dynamicContainer = [
            '#type' => 'container',
            '#attributes' => ['id' => 'mobility-dynamic-wrapper'],
            'child' => ['#markup' => 'Content'],
        ];

        $form = [
            'mobility_details' => [
                'dynamic_container' => $dynamicContainer,
            ],
        ];

        $formState = new FormState();
        $result = $this->form->mobilityAjaxCallback($form, $formState);

        $this->assertEquals($dynamicContainer, $result);
    }
}
