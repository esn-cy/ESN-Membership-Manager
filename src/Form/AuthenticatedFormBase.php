<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class AuthenticatedFormBase extends FormBase
{
    protected Connection $database;
    protected ApplicationStorage $applicationStorage;
    protected ClientInterface $httpClient;
    protected LoggerChannelInterface $logger;

    protected bool $isAuthenticated = false;
    protected ?string $authenticatedEmail = null;
    protected bool $isDialogAdded = false;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        $this->database = $database;
        $this->applicationStorage = $applicationStorage;
        $this->httpClient = $httpClient;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var ClientInterface $httpClient */
        $httpClient = $container->get('http_client');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $entityTypeManager,
            $httpClient,
            $loggerFactory,
        );
    }

    /**
     * @return string Either `login` or `register`.
     */
    abstract protected function getAuthenticationType(): string;

    /**
     * @return bool Whether an unauthenticated user will be prompted with the login window upon form access.
     */
    abstract protected function isAuthenticationRequired(): bool;

    /**
     * @return MarkupInterface|string The header markup to be display on top of the authentication dialog.
     */
    abstract protected function headerMarkup(): MarkupInterface|string;

    /**
     * {@inheritDoc}
     */
    abstract public function getFormId(): string;

    /**
     * {@inheritDoc}
     */
    abstract public function submitForm(array &$form, FormStateInterface $form_state);

    /**
     * {@inheritDoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['#cache'] = [
            'max-age' => 0,
        ];

        $form_state->disableCache();

        $form['#prefix'] = '<div id="authenticated-form-wrapper">';
        $form['#suffix'] = '</div>';

        $this->isDialogAdded = false;

        $session = $this->getRequest()->getSession();
        $emailAuthenticationData = $session->get($this->getAuthenticationType() . '_email_authentication_data', []);

        $this->isAuthenticated = !empty($emailAuthenticationData['auth_success']) || $form_state->get('auth_success');
        if ($this->isAuthenticated) {
            $this->authenticatedEmail = strtolower($session->get($this->getAuthenticationType() . '_verified_email') ?? $emailAuthenticationData['email']);
        } else {
            $this->authenticatedEmail = null;
            if ($this->isAuthenticationRequired()) {
                $this->addAuthenticationDialog($form, $form_state);
            }
        }

        return $form;
    }

    /**
     * AJAX callback to update the form.
     * @noinspection PhpUnusedParameterInspection
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     */
    public function updateForm(array &$form, FormStateInterface $form_state): array
    {
        return $form;
    }

    /**
     * Submit handler for sending the verification code.
     * @noinspection PhpUnusedParameterInspection
     */
    public function sendCode(array &$form, FormStateInterface $form_state): void
    {
        $email = trim($form_state->getValue('email'));

        $emailExists = $this->applicationStorage->countByEmail($email) > 0;

        if ($this->getAuthenticationType() == 'register' && $emailExists) {
            $form_state->set('api_message', $this->t('You have already made an application with this email address.'));
            $form_state->set('api_message_type', 'status');

            $session = $this->getRequest()->getSession();
            $authenticationData = $session->get($this->getAuthenticationType() . '_email_authentication_data', []);
            $authenticationData['email_exists'] = true;
            $authenticationData['email'] = $email;
            $session->set($this->getAuthenticationType() . '_email_authentication_data', $authenticationData);

            $form_state->setRebuild();
            return;
        }

        if ($this->getAuthenticationType() == 'login' && !$emailExists) {
            $this->setSentCodeState($email, $form_state);
            $form_state->setRebuild();
            return;
        }

        try {
            $response = $this->httpClient->post(
                Url::fromRoute(
                    'esn_membership_manager.authentication_code',
                    ['type' => $this->getAuthenticationType()],
                    ['absolute' => true]
                )->toString(),
                [
                    'json' => ['email' => $email]
                ]
            );

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['error'])) {
                $form_state->set('api_message', $body['error']);
                $form_state->set('api_message_type', 'error');
            } else {
                $this->setSentCodeState($email, $form_state);
            }
        } catch (GuzzleException) {
            $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
            $form_state->set('api_message_type', 'error');
        }
    }

    private function setSentCodeState(string $email, FormStateInterface $form_state): void
    {
        $form_state->set('api_message', $body['message'] ?? $this->t('Verification email sent.'));
        $form_state->set('api_message_type', 'status');
        $form_state->set('code_sent', true);

        $session = $this->getRequest()->getSession();
        $authenticationData = $session->get($this->getAuthenticationType() . '_email_authentication_data', []);
        $authenticationData['code_sent'] = true;
        $authenticationData['email'] = $email;
        $session->set($this->getAuthenticationType() . '_email_authentication_data', $authenticationData);

        $form_state->setRebuild();
    }

    /**
     * Submit handler for verifying the verification code.
     * @noinspection PhpUnusedParameterInspection
     */
    public function verifyCode(array &$form, FormStateInterface $form_state): void
    {
        $email = strtolower($form_state->getValue('email'));
        $code = $form_state->getValue('verification_code');

        try {
            $response = $this->httpClient->post(
                Url::fromRoute(
                    'esn_membership_manager.authentication_verify',
                    ['type' => $this->getAuthenticationType()],
                    ['absolute' => true]
                )->toString(),
                [
                    'json' => [
                        'email' => $email,
                        'code' => $code,
                    ]
                ]
            );

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['error'])) {
                $form_state->set('api_message', $body['error']);
                $form_state->set('api_message_type', 'error');
            } else {
                if ($this->getAuthenticationType() == 'register') {
                    try {
                        $this->database->merge('esn_membership_manager_in_progress_applications')
                            ->key('email', $email)
                            ->fields([
                                'date_created' => (new DrupalDateTime())->format('Y-m-d\TH:i:s')
                            ])
                            ->execute();
                    } catch (Exception $e) {
                        $this->logger->error('Unable to create in progress application. @error.', ['@error' => $e->getMessage()]);
                        $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
                        $form_state->set('api_message_type', 'error');
                        $form_state->setRebuild();
                        return;
                    }
                }

                $form_state->set('api_message', $body['message'] ?? $this->t('Email address verified.'));
                $form_state->set('api_message_type', 'status');
                $form_state->set('auth_success', true);

                $session = $this->getRequest()->getSession();
                $authenticationData = $session->get($this->getAuthenticationType() . '_email_authentication_data', []);
                $authenticationData['auth_success'] = true;
                $session->set($this->getAuthenticationType() . '_email_authentication_data', $authenticationData);

                if ($this->getAuthenticationType() == 'register') {
                    $savedData = $session->get('application_form_saved_data', []);
                    $savedData['email'] = $email;
                    $savedData['verification_code'] = $code;
                    $session->set('application_form_saved_data', $savedData);
                }
            }
        } catch (GuzzleException) {
            $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
            $form_state->set('api_message_type', 'error');
        }

        $form_state->setRebuild();
    }

    protected function addAuthenticationDialog(array &$form, FormStateInterface $form_state): void
    {
        $session = $this->getRequest()->getSession();
        $authenticationData = $session->get($this->getAuthenticationType() . '_email_authentication_data', []);

        $email = strtolower($session->get($this->getAuthenticationType() . '_verified_email') ?? $authenticationData['email'] ?? $form_state->getValue('email') ?? '');
        if (empty($email)) {
            $authenticationData = [];
            $session->remove($this->getAuthenticationType() . '_email_authentication_data');
        }

        $isCodeSent = !empty($authenticationData['code_sent']) || $form_state->get('code_sent');
        $isCodeVerified = !empty($authenticationData['auth_success']) || $form_state->get('auth_success');
        $emailExists = !empty($authenticationData['email_exists']);

        if ($isCodeVerified) {
            return;
        }

        $form['#attached']['library'][] = 'esn_membership_manager/login_form';
        $form['#attributes']['class'][] = 'esn-membership-manager-login-form';

        $form['header'] = [
            '#markup' => $this->headerMarkup(),
            '#weight' => -30,
        ];

        $form['auth'] = [
            '#type' => 'fieldset'
        ];

        $form['auth']['email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email'),
            '#description' => $this->t('A verification code will be sent to this email address.'),
            '#required' => TRUE,
            '#defailt_value' => $form_state->getValue('email') ?? $authenticationData['email'] ?? '',
        ];

        $apiMessage = $form_state->get('api_message');
        if ($apiMessage) {
            $messageType = $form_state->get('api_message_type') ?? 'status';
            $form['auth']['message'] = [
                '#markup' => '<p class="alert alert-' . ($messageType == 'status' ? 'success' : 'warning') . '">' . $apiMessage . '</p>',
            ];
        }

        if ($this->getAuthenticationType() == 'register' && $emailExists) {
            $this->isDialogAdded = true;
            return;
        }

        if (!$isCodeSent) {
            $form['auth']['send_code'] = [
                '#type' => 'submit',
                '#name' => 'send_code_button',
                '#value' => $this->t('Send Verification Email'),
                '#submit' => ['::sendCode'],
                '#ajax' => [
                    'callback' => '::updateForm',
                    'wrapper' => 'authenticated-form-wrapper',
                ],
                '#limit_validation_errors' => [['email']],
            ];
        } else {
            $form['auth']['verification_code'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Verification Code'),
                '#required' => TRUE,
                '#default_value' => $form_state->getValue('verification_code') ?? '',
            ];

            $form['auth']['verify_submit'] = [
                '#type' => 'submit',
                '#name' => 'verify_button',
                '#value' => $this->t('Verify Code'),
                '#submit' => ['::verifyCode'],
                '#ajax' => [
                    'callback' => '::updateForm',
                    'wrapper' => 'authenticated-form-wrapper',
                ],
                '#limit_validation_errors' => [['email'], ['verification_code']],
            ];
        }

        $this->isDialogAdded = true;
    }

    /**
     * Helper method to load the current user's membership application.
     * @return ApplicationInterface|null
     * The application entity, or null if not found/error.
     * @throws Exception
     */
    protected function getApplication(bool $throw = false): ?ApplicationInterface
    {
        if (empty($this->authenticatedEmail)) {
            $this->messenger()->addError($this->t('Could not verify your session. Please log in again.'));
            if ($throw) {
                throw new Exception('Not authenticated.', 401);
            }
            return null;
        }

        $application = $this->applicationStorage->getByEmailAddress($this->authenticatedEmail);
        if (empty($application)) {
            $this->messenger()->addError($this->t('No active application found for this email.'));
            if ($throw) {
                throw new Exception('Application not found.', 404);
            }
            return null;
        }

        return $application;
    }
}