<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\Merge;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Form\AuthenticatedFormBase;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\TransferException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Concrete implementation of AuthenticatedFormBase for unit testing.
 */
class TestAuthenticatedForm extends AuthenticatedFormBase
{
    private string $authType = 'login';
    private bool $authRequired = true;

    public function setAuthType(string $type): void
    {
        $this->authType = $type;
    }

    public function setAuthenticatedEmail(?string $email): void
    {
        $this->authenticatedEmail = $email;
    }

    public function isAuthenticated(): bool
    {
        return $this->isAuthenticated;
    }

    public function isDialogAdded(): bool
    {
        return $this->isDialogAdded;
    }

    public function callAddAuthenticationDialog(array &$form, FormStateInterface $form_state): void
    {
        $this->addAuthenticationDialog($form, $form_state);
    }

    /**
     * @throws Exception
     */
    public function callGetApplication(bool $throw = false): ?ApplicationInterface
    {
        return $this->getApplication($throw);
    }

    protected function getAuthenticationType(): string
    {
        return $this->authType;
    }

    protected function isAuthenticationRequired(): bool
    {
        return $this->authRequired;
    }

    protected function headerMarkup(): MarkupInterface|string
    {
        return '<h3>Authentication</h3>';
    }

    public function getFormId(): string
    {
        return 'test_authenticated_form';
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
    }
}

/**
 * Unit tests for AuthenticatedFormBase.
 *
 * @covers \Drupal\esn_membership_manager\Form\AuthenticatedFormBase
 * @group esn_membership_manager
 */
class AuthenticatedFormBaseTest extends MembershipManagerTestCase
{
    private Connection $database;
    private ApplicationStorage $applicationStorage;
    private Client $httpClient;
    private LoggerChannelInterface $logger;
    private MessengerInterface $messenger;
    private Session $session;
    private TestAuthenticatedForm $form;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->database = $this->createMock(Connection::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->httpClient = $this->createMock(Client::class);
        $this->logger = $this->createMock(LoggerChannelInterface::class);
        $this->messenger = $this->createMock(MessengerInterface::class);

        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')->with('membership_application')->willReturn($this->applicationStorage);

        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);

        $requestStack = new RequestStack();
        $requestStack->push($request);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')->willReturn('https://example.com/auth');

        $this->container->set('database', $this->database);
        $this->container->set('entity_type.manager', $entityTypeManager);
        $this->container->set('http_client', $this->httpClient);
        $this->container->set('logger.factory', $this->getLoggerFactoryMock($this->logger));
        $this->container->set('messenger', $this->messenger);
        $this->container->set('request_stack', $requestStack);
        $this->container->set('url_generator', $urlGenerator);

        $this->form = new TestAuthenticatedForm(
            $this->database,
            $entityTypeManager,
            $this->httpClient,
            $this->getLoggerFactoryMock($this->logger)
        );
        $this->form->setMessenger($this->messenger);
        $this->form->setStringTranslation($this->container->get('string_translation'));
        $this->form->setRequestStack($requestStack);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $form = TestAuthenticatedForm::create($this->container);
        $this->assertInstanceOf(TestAuthenticatedForm::class, $form);
    }

    public function testUpdateForm(): void
    {
        $formArray = ['#id' => 'form_123'];
        $formState = new FormState();
        $this->assertEquals($formArray, $this->form->updateForm($formArray, $formState));
    }

    public function testSendCodeRegisterWhenEmailAlreadyExists(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'existing@example.com');
        $this->form->setAuthType('register');

        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('existing@example.com')
            ->willReturn(1);

        $this->httpClient->expects($this->never())->method('post');

        $this->form->sendCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertEquals('You have already made an application with this email address.', (string)$formState->get('api_message'));
        $this->assertEquals('status', $formState->get('api_message_type'));

        $authData = $this->session->get('register_email_authentication_data');
        $this->assertTrue($authData['email_exists']);
        $this->assertEquals('existing@example.com', $authData['email']);
    }

    public function testSendCodeLoginWhenEmailDoesNotExist(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'unknown@example.com');
        $this->form->setAuthType('login');

        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('unknown@example.com')
            ->willReturn(0);

        $this->httpClient->expects($this->never())->method('post');

        $this->form->sendCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertTrue($formState->get('code_sent'));
        $this->assertEquals('Verification email sent.', (string)$formState->get('api_message'));

        $authData = $this->session->get('login_email_authentication_data');
        $this->assertTrue($authData['code_sent']);
        $this->assertEquals('unknown@example.com', $authData['email']);
    }

    public function testSendCodeHttpReturnsError(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'user@example.com');
        $this->form->setAuthType('login');

        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('user@example.com')
            ->willReturn(1);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode(['error' => 'Rate limit exceeded']));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $this->form->sendCode($form, $formState);

        $this->assertEquals('Rate limit exceeded', $formState->get('api_message'));
        $this->assertEquals('error', $formState->get('api_message_type'));
    }

    public function testSendCodeHttpSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'user@example.com');
        $this->form->setAuthType('login');

        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('user@example.com')
            ->willReturn(1);

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode(['message' => 'Custom code sent message']));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $this->form->sendCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertTrue($formState->get('code_sent'));
        $this->assertEquals('Verification email sent.', (string)$formState->get('api_message'));
    }

    public function testSendCodeHttpException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'user@example.com');
        $this->form->setAuthType('login');

        $this->applicationStorage->expects($this->once())
            ->method('countByEmail')
            ->with('user@example.com')
            ->willReturn(1);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willThrowException(new TransferException('Connection refused'));

        $this->form->sendCode($form, $formState);

        $this->assertEquals('There was an issue processing your request. Please try again later.', (string)$formState->get('api_message'));
        $this->assertEquals('error', $formState->get('api_message_type'));
    }

    public function testVerifyCodeHttpReturnsError(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'user@example.com');
        $formState->setValue('verification_code', '123456');
        $this->form->setAuthType('login');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode(['error' => 'Invalid code']));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $this->form->verifyCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertEquals('Invalid code', $formState->get('api_message'));
        $this->assertEquals('error', $formState->get('api_message_type'));
    }

    public function testVerifyCodeRegisterSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'newuser@example.com');
        $formState->setValue('verification_code', '654321');
        $this->form->setAuthType('register');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode(['message' => 'Success']));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $merge = $this->createMock(Merge::class);
        $merge->method('key')->willReturnSelf();
        $merge->method('fields')->willReturnSelf();
        $merge->method('execute')->willReturn(1);

        $this->database->expects($this->once())
            ->method('merge')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($merge);

        $this->form->verifyCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertTrue($formState->get('auth_success'));
        $this->assertEquals('Success', $formState->get('api_message'));
        $this->assertEquals('status', $formState->get('api_message_type'));

        $authData = $this->session->get('register_email_authentication_data');
        $this->assertTrue($authData['auth_success']);

        $savedData = $this->session->get('application_form_saved_data');
        $this->assertEquals('newuser@example.com', $savedData['email']);
        $this->assertEquals('654321', $savedData['verification_code']);
    }

    public function testVerifyCodeRegisterDatabaseMergeException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'newuser@example.com');
        $formState->setValue('verification_code', '654321');
        $this->form->setAuthType('register');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode([]));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $merge = $this->createMock(Merge::class);
        $merge->method('key')->willReturnSelf();
        $merge->method('fields')->willReturnSelf();
        $merge->method('execute')->willThrowException(new Exception('DB lock error'));

        $this->database->expects($this->once())
            ->method('merge')
            ->with('esn_membership_manager_in_progress_applications')
            ->willReturn($merge);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unable to create in progress application. @error.',
                ['@error' => 'DB lock error']
            );

        $this->form->verifyCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertEquals('There was an issue processing your request. Please try again later.', (string)$formState->get('api_message'));
        $this->assertEquals('error', $formState->get('api_message_type'));
    }

    public function testVerifyCodeLoginSuccess(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'loginuser@example.com');
        $formState->setValue('verification_code', '111222');
        $this->form->setAuthType('login');

        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getContents')->willReturn(json_encode([]));

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getBody')->willReturn($stream);

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willReturn($response);

        $this->database->expects($this->never())->method('merge');

        $this->form->verifyCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertTrue($formState->get('auth_success'));
        $this->assertEquals('Email address verified.', (string)$formState->get('api_message'));
        $this->assertEquals('status', $formState->get('api_message_type'));

        $authData = $this->session->get('login_email_authentication_data');
        $this->assertTrue($authData['auth_success']);
    }

    public function testVerifyCodeHttpException(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'user@example.com');
        $formState->setValue('verification_code', '123456');
        $this->form->setAuthType('login');

        $this->httpClient->expects($this->once())
            ->method('post')
            ->willThrowException(new TransferException('Timeout'));

        $this->form->verifyCode($form, $formState);

        $this->assertTrue($formState->isRebuilding());
        $this->assertEquals('There was an issue processing your request. Please try again later.', (string)$formState->get('api_message'));
        $this->assertEquals('error', $formState->get('api_message_type'));
    }

    public function testAddAuthenticationDialogWhenAlreadyVerified(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->set('auth_success', true);

        $this->form->callAddAuthenticationDialog($form, $formState);

        $this->assertEmpty($form);
        $this->assertFalse($this->form->isDialogAdded());
    }

    public function testAddAuthenticationDialogInitialStateWithoutCodeSent(): void
    {
        $form = [];
        $formState = new FormState();

        $this->form->callAddAuthenticationDialog($form, $formState);

        $this->assertTrue($this->form->isDialogAdded());
        $this->assertArrayHasKey('auth', $form);
        $this->assertArrayHasKey('email', $form['auth']);
        $this->assertArrayHasKey('send_code', $form['auth']);
        $this->assertArrayNotHasKey('verification_code', $form['auth']);
    }

    public function testAddAuthenticationDialogWithApiMessageAndCodeSent(): void
    {
        $form = [];
        $formState = new FormState();
        $formState->setValue('email', 'test@example.com');
        $formState->set('code_sent', true);
        $formState->set('api_message', 'Invalid code entered');
        $formState->set('api_message_type', 'error');

        $this->form->callAddAuthenticationDialog($form, $formState);

        $this->assertTrue($this->form->isDialogAdded());
        $this->assertArrayHasKey('auth', $form);
        $this->assertArrayHasKey('message', $form['auth']);
        $this->assertStringContainsString('alert-warning', $form['auth']['message']['#markup']);
        $this->assertArrayHasKey('verification_code', $form['auth']);
        $this->assertArrayHasKey('verify_submit', $form['auth']);
    }

    public function testAddAuthenticationDialogRegisterWhenEmailExists(): void
    {
        $form = [];
        $formState = new FormState();
        $this->form->setAuthType('register');

        $this->session->set('register_email_authentication_data', [
            'email' => 'exists@example.com',
            'email_exists' => true,
        ]);

        $this->form->callAddAuthenticationDialog($form, $formState);

        $this->assertTrue($this->form->isDialogAdded());
        $this->assertArrayHasKey('auth', $form);
        $this->assertArrayNotHasKey('send_code', $form['auth']);
        $this->assertArrayNotHasKey('verify_submit', $form['auth']);
    }

    /**
     * @throws Exception
     */
    public function testGetApplicationNotAuthenticatedWithoutThrow(): void
    {
        $this->form->setAuthenticatedEmail(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not verify your session. Please log in again.'));

        $result = $this->form->callGetApplication();
        $this->assertNull($result);
    }

    public function testGetApplicationNotAuthenticatedWithThrow(): void
    {
        $this->form->setAuthenticatedEmail(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('Could not verify your session. Please log in again.'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Not authenticated.');
        $this->expectExceptionCode(401);

        $this->form->callGetApplication(true);
    }

    /**
     * @throws Exception
     */
    public function testGetApplicationNotFoundWithoutThrow(): void
    {
        $this->form->setAuthenticatedEmail('notfound@example.com');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with('notfound@example.com')
            ->willReturn(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('No active application found for this email.'));

        $result = $this->form->callGetApplication();
        $this->assertNull($result);
    }

    public function testGetApplicationNotFoundWithThrow(): void
    {
        $this->form->setAuthenticatedEmail('notfound@example.com');

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with('notfound@example.com')
            ->willReturn(null);

        $this->messenger->expects($this->once())
            ->method('addError')
            ->with($this->matchesString('No active application found for this email.'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Application not found.');
        $this->expectExceptionCode(404);

        $this->form->callGetApplication(true);
    }

    /**
     * @throws Exception
     */
    public function testGetApplicationSuccess(): void
    {
        $this->form->setAuthenticatedEmail('found@example.com');

        $application = $this->createMock(ApplicationInterface::class);

        $this->applicationStorage->expects($this->once())
            ->method('getByEmailAddress')
            ->with('found@example.com')
            ->willReturn($application);

        $this->messenger->expects($this->never())->method('addError');

        $result = $this->form->callGetApplication();
        $this->assertSame($application, $result);
    }
}
