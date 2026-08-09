<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\esn_accounts_api\Entity\Organisation;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Plugin\Action\ApproveApplication;
use Drupal\esn_membership_manager\Service\DiditService;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Utility\ApprovalStatuses;
use Drupal\esn_membership_manager\Utility\MobilityStatuses;
use Drupal\esn_membership_manager\Utility\Nationalities;
use Drupal\omnia\Config\OmniaSettings;
use Exception;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApplicationForm extends AuthenticatedFormBase
{
    protected MembershipSettings $membershipSettings;
    protected OmniaSettings $omniaSettings;
    protected FileService $fileService;
    protected EmailManager $emailManager;
    protected Extension $module;
    protected ApplicationStorage $applicationStorage;
    protected ApproveApplication $approveApplication;
    protected EntityStorageInterface $organisationStorage;
    protected DiditService $diditService;
    protected Nationalities $nationalities;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        ClientInterface               $httpClient,
        FileService                   $fileService,
        EmailManager                  $emailManager,
        ModuleHandlerInterface        $moduleHandler,
        ActionManager                 $actionManager,
        DiditService                  $diditService,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        /** @var ApproveApplication $approveApplication */
        $approveApplication = $actionManager->createInstance('esn_membership_manager_approve');

        parent::__construct($database, $entityTypeManager, $httpClient, $loggerFactory);
        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->omniaSettings = new OmniaSettings($configFactory);
        $this->fileService = $fileService;
        $this->emailManager = $emailManager;
        $this->module = $moduleHandler->getModule('esn_membership_manager');
        $this->applicationStorage = $applicationStorage;
        $this->approveApplication = $approveApplication;
        $this->organisationStorage = $entityTypeManager->getStorage('esn_organisation');
        $this->diditService = $diditService;
        $this->nationalities = new Nationalities($moduleHandler);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var ClientInterface $httpClient */
        $httpClient = $container->get('http_client');

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var DiditService $diditService */
        $diditService = $container->get('esn_membership_manager.didit_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configFactory,
            $database,
            $entityTypeManager,
            $httpClient,
            $fileService,
            $emailManager,
            $moduleHandler,
            $actionManager,
            $diditService,
            $loggerFactory,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_application_form';
    }

    protected function getAuthenticationType(): string
    {
        return 'register';
    }

    /**
     * {@inheritDoc}
     */
    protected function isAuthenticationRequired(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    protected function headerMarkup(): MarkupInterface|string
    {
        $passName = $this->membershipSettings->getPassName();

        return Markup::create('<h2>' . $this->t('Apply for an ESNcard / @scheme', ['@scheme' => $passName]) . '</h2>');
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $session = $this->getRequest()->getSession();
        $savedData = $session->get('application_form_saved_data', []);

        $form = parent::buildForm($form, $form_state);
        if ($this->isDialogAdded) {
            $session->remove('application_form_verification_data');

            return $form;
        }

        $passName = $this->membershipSettings->getPassName();

        $form['header'] = [
            '#markup' => Markup::create(
                '<h2>' . $this->t('Apply for an ESNcard / @scheme', ['@scheme' => $passName]) . '</h2>' .
                '<p>' . $this->t('The @scheme is your digital identifier. It verifies your status as a mobility participant and grants you access to exclusive events.', ['@scheme' => $passName]) . '</p>' .
                '<p>' . $this->t('The ESNcard is the official physical membership card of the Erasmus Student Network. It provides all the benefits of the @scheme, plus access to thousands of discounts at major brands and local businesses across Europe.', ['@scheme' => $passName]) . '</p>'
            ),
            '#weight' => -30,
        ];

        $form['#attached']['library'][] = 'esn_membership_manager/application_form';
        $form['#attributes']['class'][] = 'esn-membership-manager-form';

        $form['email'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Email'),
        ];

        $form['email']['email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email'),
            '#description' => $this->t('A verification code will be sent to this email address.'),
            '#required' => TRUE,
            '#disabled' => TRUE,
            '#default_value' => $this->authenticatedEmail,
        ];

        try {
            $application = $this->database->select('esn_membership_manager_in_progress_applications', 'i')
                ->fields('i')
                ->condition('email', $this->authenticatedEmail)
                ->execute()
                ->fetchAssoc();
        } catch (Exception) {
        }

        $form['personal_details'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Personal Details'),
        ];

        $personalFieldsDisabled = false;
        if ($this->membershipSettings->getDiditSwitch() && !empty($application)) {
            $verificationData = $session->get('application_form_verification_data', []);
            $verificationData['id_verification_token'] = $application['didit_session_token'];
            $session->set('application_form_verification_data', $verificationData);

            $diditStatus = $application['didit_status'] ?? null;
            if (!empty($diditStatus)) {
                switch ($diditStatus) {
                    case 'Approved':
                        $form['personal_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-success">' . $this->t('You have successfully verified your identity.') . '</p>'),
                        ];

                        $savedData = $session->get('application_form_saved_data', []);
                        $savedData['name'] = $application['id_name'];
                        $savedData['surname'] = $application['id_surname'];
                        $savedData['nationality'] = $application['id_nationality'];
                        $savedData['dob'] = $application['id_dob'];
                        $session->set('application_form_saved_data', $savedData);

                        $verificationData['id_verified'] = true;
                        $session->set('application_form_verification_data', $verificationData);

                        $personalFieldsDisabled = true;
                        break;
                    case 'Declined':
                    case 'In Review':
                        $form['personal_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('Your identity verification has failed. Please fill in the following fields manually.') . '</p>'),
                        ];
                        break;
                    case 'Failed':
                        $form['personal_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('There was an issue verifying your identity. Please fill in the following fields manually.') . '</p>'),
                        ];
                        break;
                    case 'Not Started':
                        break;
                    default:
                        $form['personal_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('Please fill in the following fields manually.') . '</p>'),
                        ];
                        break;
                }
            }
        }

        if (!$personalFieldsDisabled) {
            $form['personal_details']['online_title'] = [
                '#markup' => '<h4 class="verification-subtitle">' . $this->t('Instant (Recommended)') . '</h4>',
            ];

            $form['personal_details']['online_button'] = [
                '#type' => 'submit',
                '#value' => $this->t('Verify ID Online'),
                '#name' => 'didit_button',
                '#attributes' => [
                    'class' => ['btn-primary', 'btn', 'online-verify-btn'],
                ],
                '#ajax' => [
                    'callback' => '::redirectToDidit',
                    'event' => 'click',
                ],
            ];

            $form['personal_details']['tos_text'] = [
                '#markup' => '<div class="description verification-tos">' . $this->t('By clicking verify you agree with our and Didit\'s ') . '<a href="https://didit.me/terms/identity-verification/" target="_blank">' . $this->t('End User Terms of Service') . '</a>.' . '</div>',
            ];

            $form['personal_details']['divider'] = [
                '#markup' => '<div class="verification-divider"><span>' . $this->t('OR') . '</span></div>',
            ];

            $form['personal_details']['manual_title'] = [
                '#markup' => '<h4 class="verification-subtitle">' . $this->t('Manual Processing (Up to 7 days)') . '</h4>',
            ];
        }

        $form['personal_details']['name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#required' => TRUE,
            '#disabled' => $personalFieldsDisabled,
            '#default_value' => $form_state->getValue('name') ?? $savedData['name'] ?? '',
        ];

        $form['personal_details']['surname'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Surname'),
            '#required' => TRUE,
            '#disabled' => $personalFieldsDisabled,
            '#default_value' => $form_state->getValue('surname') ?? $savedData['surname'] ?? '',
        ];

        $form['personal_details']['nationality'] = [
            '#type' => 'select',
            '#title' => $this->t('Nationality'),
            '#options' => $this->nationalities->get(),
            '#empty_option' => $this->t('- Select -'),
            '#required' => TRUE,
            '#disabled' => $personalFieldsDisabled,
            '#default_value' => $form_state->getValue('nationality') ?? $savedData['nationality'] ?? '',
        ];

        $form['personal_details']['dob'] = [
            '#type' => 'date',
            '#title' => $this->t('Date of Birth'),
            '#required' => TRUE,
            '#disabled' => $personalFieldsDisabled,
            '#default_value' => $form_state->getValue('dob') ?? $savedData['dob'] ?? 0,
        ];

        if (!$personalFieldsDisabled) {
            $form['personal_details']['id_document'] = [
                '#type' => 'managed_file',
                '#title' => $this->t('Copy of ID or Passport'),
                '#description' => $this->t('Upload a scan of your ID or Passport for verification.'),
                '#upload_location' => 'membership://temp_uploads/',
                '#upload_validators' => [
                    'file_validate_extensions' => ['jpg jpeg png pdf'],
                    'file_validate_size' => [8 * 1024 * 1024]
                ],
                '#attributes' => [
                    'accept' => 'image/jpeg, image/png, application/pdf',
                ],
                '#required' => TRUE,
                '#default_value' => $form_state->getValue('id_document') ?? $savedData['id_document'] ?? '',
            ];
        }

        $form['mobility_details'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Mobility & Status'),
        ];

        $sections = [];

        if (!$this->omniaSettings->getSectionMode()) {
            $noID = $this->omniaSettings->getNationalOrganisationID();
            /** @var Organisation $nationalOrganisation */
            $nationalOrganisation = $this->organisationStorage->load($noID);
            if ($nationalOrganisation) {
                /** @var Organisation[] $sectionEntities */
                $sectionEntities = $this->organisationStorage->loadByProperties(['type' => 'section', 'country_code' => $nationalOrganisation->getCountryCode()]);
                foreach ($sectionEntities as $section) {
                    $title = $section->getTitle();
                    $sections[$title] = $title;
                }
                ksort($sections);
            }
        } else {
            $sectionName = $this->omniaSettings->getNationalOrganisationID();
            if ($sectionName) {
                $sections[$sectionName] = $sectionName;
            }
        }

        if (count($sections) > 1) {
            $form['mobility_details']['section'] = [
                '#type' => 'select',
                '#title' => $this->t('Local Section Name'),
                '#description' => $this->t('Select your local section.'),
                '#options' => $sections,
                '#empty_option' => $this->t('- Select -'),
                '#default_value' => $form_state->getValue('section') ?? $savedData['section'] ?? '',
                '#required' => TRUE,
            ];
        } elseif (count($sections) === 1) {
            $form['mobility_details']['section'] = [
                '#type' => 'value',
                '#value' => reset($sections),
            ];
        }

        $statusFieldsDisabled = false;
        if (!empty($application)) {
            $esnStatus = $application['esn_status'] ?? null;
            if (!empty($esnStatus)) {
                switch ($esnStatus) {
                    case 'Failed':
                        $form['mobility_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('There was an issue signing you in. Please fill in the following fields manually.') . '</p>'),
                        ];
                        break;
                    case 'No Roles':
                        $form['mobility_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('You don\'t have any roles associated with your account. Please fill in the following fields manually.') . '</p>'),
                        ];
                        break;
                    case 'Foreign Roles':
                        $form['mobility_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t("You don't have any roles in {$this->omniaSettings->getOrganisationName()}. Please fill in the following fields manually.") . '</p>'),
                        ];
                        break;
                    case 'Success':
                        $form['mobility_details']['verified_status'] = [
                            '#markup' => Markup::create('<p class="alert alert-success">' . $this->t('You have successfully verified your status.') . '</p>'),
                        ];

                        $savedData = $session->get('application_form_saved_data', []);
                        $savedData['status'] = strtolower(str_replace(' ', '_', $application['status_mobility']));
                        $savedData['host'] = $application['status_host_institution'];
                        $session->set('application_form_saved_data', $savedData);

                        $verificationData = $session->get('application_form_verification_data', []);
                        $verificationData['status_verified'] = true;
                        $session->set('application_form_verification_data', $verificationData);

                        $statusFieldsDisabled = true;
                        break;
                }
            }
        }

        $form['mobility_details']['status'] = [
            '#type' => 'select',
            '#title' => $this->t('Current Status'),
            '#options' => MobilityStatuses::getGroupedOptions(),
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $form_state->getValue('status') ?? $savedData['status'] ?? '',
            '#required' => TRUE,
            '#disabled' => $statusFieldsDisabled,
            '#ajax' => [
                'callback' => '::mobilityAjaxCallback',
                'wrapper' => 'mobility-dynamic-wrapper',
            ],
        ];

        $form['mobility_details']['dynamic_container'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'mobility-dynamic-wrapper'],
        ];

        $status = $form_state->getValue('status') ?? $savedData['status'] ?? $form_state->getUserInput()['status'] ?? NULL;

        $showDynamicFields = !empty($status);
        if ($showDynamicFields) {
            $labels = MobilityStatuses::getLabels($status);
            $organizationLabel = $labels['organization_label'];
            $proofLabelText = $labels['proof_label'];

            if (!$statusFieldsDisabled && str_starts_with($status, 'esn_')) {
                $form['mobility_details']['dynamic_container']['online_title'] = [
                    '#markup' => '<h4 class="verification-subtitle">' . $this->t('Instant (Recommended)') . '</h4>',
                ];

                $form['mobility_details']['dynamic_container']['online_button'] = [
                    '#type' => 'submit',
                    '#value' => $this->t('Login with ESN Accounts'),
                    '#name' => 'esn_button',
                    '#attributes' => [
                        'class' => ['btn-primary', 'btn', 'online-verify-btn'],
                    ],
                    '#ajax' => [
                        'callback' => '::redirectToESNAccounts',
                        'event' => 'click',
                    ],
                ];

                $form['mobility_details']['dynamic_container']['divider'] = [
                    '#markup' => '<div class="verification-divider"><span>' . $this->t('OR') . '</span></div>',
                ];

                $form['mobility_details']['dynamic_container']['manual_title'] = [
                    '#markup' => '<h4 class="verification-subtitle">' . $this->t('Manual Processing (Up to 7 days)') . '</h4>',
                ];
            }

            $form['mobility_details']['dynamic_container']['host'] = [
                '#type' => 'textfield',
                '#description' => 'You need to enter institution that\'s hosting you here, not the one from your country of origin.',
                '#title' => $organizationLabel,
                '#required' => TRUE,
                '#disabled' => $statusFieldsDisabled,
                '#default_value' => $form_state->getValue('host') ?? $savedData['host'] ?? '',
            ];

            if (!$statusFieldsDisabled) {
                $form['mobility_details']['dynamic_container']['proof_help'] = [
                    '#type' => 'item',
                    '#markup' => '<div class="description">' . $this->t('Please upload your <strong>@proof</strong>.', ['@proof' => $proofLabelText]) . '</div>',
                ];

                $form['mobility_details']['dynamic_container']['proof_of_status'] = [
                    '#type' => 'managed_file',
                    '#title' => $this->t('Proof of Status'),
                    '#upload_location' => 'membership://temp_uploads/',
                    '#upload_validators' => [
                        'file_validate_extensions' => ['jpg jpeg png pdf'],
                        'file_validate_size' => [8 * 1024 * 1024]
                    ],
                    '#attributes' => [
                        'accept' => 'image/jpeg, image/png, application/pdf',
                    ],
                    '#required' => TRUE,
                    '#default_value' => $form_state->getValue('proof_of_status') ?? '',
                ];
            }
        }

        $form['esncard'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('ESNcard'),
        ];

        $form['esncard']['has_esncard'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Would you like to include an ESNcard in your application?'),
            '#required' => FALSE,
            '#default_value' => $form_state->getValue('has_esncard') ?? $savedData['has_esncard'] ?? FALSE,
        ];

        $form['esncard']['esncard_requirements'] = [
            '#type' => 'container',
            '#states' => [
                'visible' => [
                    ':input[name="has_esncard"]' => ['checked' => TRUE],
                ],
            ],
        ];

        $form['esncard']['esncard_requirements']['face_photo'] = [
            '#type' => 'managed_file',
            '#title' => $this->t('Passport Style Photo'),
            '#description' => $this->t('Requirements: Full color, 4:5 aspect ratio, Face clearly visible, Min height 500px.'),
            '#upload_location' => 'membership://temp_uploads/',
            '#upload_validators' => [
                'file_validate_extensions' => ['jpg jpeg png'],
                'file_validate_image_resolution' => [0, '400x500'],
            ],
            '#states' => [
                'required' => [
                    ':input[name="has_esncard"]' => ['checked' => TRUE],
                ],
            ],
            '#default_value' => $form_state->getValue('face_photo') ?? $savedData['face_photo'] ?? '',
        ];

        $form['actions'] = [
            '#type' => 'actions',
            '#weight' => 100,
        ];

        $form['submit_legal'] = [
            '#markup' => '<div class="description verification-tos" style="font-size: inherit; color: inherit;">' .
                $this->t('By submitting this application you agree to our ') .
                '<a href="' . Url::fromRoute('esn_membership_manager.terms_of_service', [], ['absolute' => true])->toString() . '" target="_blank">' . $this->t('Terms of Service') . '</a> and ' .
                '<a href="' . Url::fromRoute('esn_membership_manager.privacy_policy', [], ['absolute' => true])->toString() . '" target="_blank">' . $this->t('Privacy Policy') . '</a>.' .
                '</div>',
        ];

        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Submit Application'),
            '#button_type' => 'primary',
        ];

        $svg = '';
        $path = $this->module->getPath() . '/assets/images/logo.svg';
        if (file_exists($path)) {
            $svg = file_get_contents($path);
        }

        $form['powered_by'] = [
            '#type' => 'item',
            '#markup' => Markup::create("<span class=\"powered-by-text\">Powered by <a href=\"https://github.com/esn-cy/ESN-Membership-Manager\" target=\"_blank\">ESN Membership Manager $svg</a>.<br>Made in Cyprus with ❤️.</span>"),
            '#weight' => 110,
        ];

        return $form;
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     * @noinspection PhpUnusedParameterInspection
     */
    public function redirectToDidit(array &$form, FormStateInterface $form_state): AjaxResponse
    {
        $response = new AjaxResponse();

        $session = $this->getRequest()->getSession();
        $verificationData = $session->get('application_form_verification_data', []);
        $token = $verificationData['id_verification_token'] ?? null;

        if (empty($token)) {
            $verificationLink = $this->diditService->createVerificationSession($this->authenticatedEmail);
        } else {
            $verificationLink = 'https://verify.didit.me/session/' . $token;
        }

        $response->addCommand(new RedirectCommand($verificationLink));

        return $response;
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     * @noinspection PhpUnusedParameterInspection
     */
    public function redirectToESNAccounts(array &$form, FormStateInterface $form_state): AjaxResponse
    {
        $response = new AjaxResponse();

        try {
            $token = strtoupper(md5(uniqid(rand(), true)));

            $serviceLink = Url::fromRoute('esn_membership_manager.apply_verify_esn', [], ['absolute' => true])->toString() . '?token=' . $token;

            $verificationLink = 'https://accounts.esn.org/cas/login?service=' . urlencode($serviceLink);

            $this->database->update('esn_membership_manager_in_progress_applications')
                ->fields(['esn_token' => $token])
                ->condition('email', $this->authenticatedEmail)
                ->execute();

            $response->addCommand(new RedirectCommand($verificationLink));
        } catch (Exception $e) {
            $this->logger->error('Failed to redirect to ESN Accounts: @message', ['@message' => $e->getMessage()]);
        }

        return $response;
    }


    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);

        $session = $this->getRequest()->getSession();
        $verificationData = $session->get('application_form_verification_data', []);
        $isVerifiedID = !empty($verificationData['id_verified']);
        $isVerifiedStatus = !empty($verificationData['status_verified']);

        if (!$this->isAuthenticated) {
            return;
        }

        $values = $form_state->getValues();
        $hasESNcard = $form_state->getValue('has_esncard', false);

        if (!$isVerifiedStatus && empty($values['proof_of_status'])) {
            $form_state->setErrorByName('proof_of_status', $this->t('Proof of status is missing. Please select your status again and re-upload the file.'));
            return;
        }

        if (!$isVerifiedID && empty($values['id_document'])) {
            $form_state->setErrorByName('id_document', $this->t('ID Document is missing. Please upload your ID document to proceed.'));
        }

        if ($hasESNcard) {
            if (empty($values['face_photo'])) {
                $form_state->setErrorByName('face_photo', $this->t('A passport style photo is required for the ESNcard.'));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $values = $form_state->getValues();
        $hasESNcard = (bool)$values['has_esncard'];

        $session = $this->getRequest()->getSession();
        $savedData = $session->get('application_form_saved_data', []);

        $email = $this->authenticatedEmail ?? strtolower(trim($form_state->getValue('email')));

        try {
            $application = $this->database->select('esn_membership_manager_in_progress_applications', 'i')
                ->fields('i')
                ->condition('email', $email)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Unable to create in progress application. @error.', ['@error' => $e->getMessage()]);
            $this->messenger()->addError($this->t('An error occurred fetching your application. Please try again.'));
            return;
        }

        $isVerifiedID = $application['didit_status'] == 'Approved';
        $isVerifiedStatus = $application['esn_status'] == 'Success';

        $filesExpected = [];
        if (!$isVerifiedStatus) {
            $filesExpected[] = 'proof_of_status';
        }
        if (!$isVerifiedID) {
            $filesExpected[] = 'id_document';
        }
        if ($hasESNcard) {
            $filesExpected[] = 'face_photo';
        }

        $filesSaved = [];
        foreach ($filesExpected as $fileKey) {
            $fileID = $values[$fileKey][0] ?? null;

            if ($this->fileService->saveApplicationFile($fileID, null)) {
                $filesSaved[] = $fileKey;
            }
        }

        if (count($filesExpected) != count($filesSaved)) {
            $this->messenger()->addError($this->t('An error occurred while saving your files. Please try again.'));
            foreach ($filesSaved as $savedFile) {
                $this->fileService->deleteApplicationFile($values[$savedFile][0] ?? null, null);
            }
            return;
        }

        $statuses = MobilityStatuses::getFlatOptions();

        try {
            $dateOfBirth = (new DrupalDateTime($isVerifiedID ? $application['id_dob'] : $values['dob']))->format('Y-m-d');
        } catch (Exception) {
            $this->messenger()->addError($this->t('Your selected date of birth is invalid. Please try again.'));
            return;
        }

        $fields = [
            ApplicationField::Name->value => trim($isVerifiedID ? $application['id_name'] : $values['name']),
            ApplicationField::Surname->value => trim($isVerifiedID ? $application['id_surname'] : $values['surname']),
            ApplicationField::Email->value => $email,
            ApplicationField::Nationality->value => trim($isVerifiedID ? $application['id_nationality'] : $values['nationality']),
            ApplicationField::DateOfBirth->value => $dateOfBirth,
            ApplicationField::Section->value => trim($values['section'] ?? 'Unknown Section'),
            ApplicationField::MobilityStatus->value => trim($isVerifiedStatus ? $application['status_mobility'] : $statuses[$values['status']]),
            ApplicationField::HostInstitution->value => trim($isVerifiedStatus ? $application['status_host_institution'] : $values['host']),
            ApplicationField::ApprovalStatus->value => ApprovalStatuses::Pending,
            ApplicationField::HasVerifiedEmail->value => 1,
            ApplicationField::HasVerifiedID->value => (int)$isVerifiedID,
            ApplicationField::HasVerifiedStatus->value => (int)$isVerifiedStatus,
            ApplicationField::DateCreated->value => (new DrupalDateTime())->format('Y-m-d\TH:i:s'),
        ];

        if (!$isVerifiedStatus) {
            $fields[ApplicationField::StatusProofFileID->value] = $values['proof_of_status'][0];
        }
        if ($isVerifiedID) {
            $pdfData = $this->diditService->getPDF($application['didit_session_id']);
            $values['id_document'][0] = $this->fileService->createApplicationFile($pdfData, "membership://temp_uploads", "id_document_{$application['id']}", null);
        }
        $fields[ApplicationField::IdentityDocumentFileID->value] = $values['id_document'][0];
        if ($hasESNcard) {
            $fields['esncard'] = 1;
            $fields[ApplicationField::FacePhotoFileID->value] = $values['face_photo'][0];
        }

        $savedApplication = null;
        try {
            $savedApplication = $this->applicationStorage->create($fields);

            $violations = $savedApplication->validate();
            if ($violations->count() > 0) {
                foreach ($violations as $violation) {
                    $this->messenger()->addError($this->t('Validation failed: @message', ['@message' => $violation->getMessage()]));
                }
                return;
            }

            $savedApplication->save();

            $targetDirectory = 'membership://' . $savedApplication->id();
            if (!$isVerifiedStatus) {
                $this->fileService->moveFile($values['proof_of_status'][0], $targetDirectory, 'status');
            }
            $this->fileService->moveFile($values['id_document'][0], $targetDirectory, 'id_document');
            if ($hasESNcard) {
                $this->fileService->moveFile($values['face_photo'][0], $targetDirectory, 'face_photo');
            }
        } catch (Exception $e) {
            if (!$isVerifiedStatus) {
                $this->fileService->deleteApplicationFile($values['proof_of_status'][0] ?? null, $savedApplication?->id());
            }
            $this->fileService->deleteApplicationFile($values['id_document'][0] ?? null, $savedApplication?->id());
            if ($hasESNcard) {
                $this->fileService->deleteApplicationFile($values['face_photo'][0] ?? null, $savedApplication?->id());
            }
            $this->messenger()->addError($this->t('Error saving application. Please try again.'));
            $this->logger->error($e->getMessage());
            return;
        }

        foreach ($filesExpected as $fileKey) {
            $fileID = $values[$fileKey][0] ?? null;
            $this->fileService->saveApplicationFile($fileID, $savedApplication->id());
        }

        if (!$hasESNcard && $isVerifiedStatus && $isVerifiedID) {
            try {
                $this->approveApplication->execute($savedApplication);
            } catch (Exception) {
            }
        } else {
            $emailParams = ['name' => trim($isVerifiedID ? $savedData['name'] : $values['name'])];

            if ($hasESNcard)
                $this->emailManager->sendEmail($email, 'both_confirmation', $emailParams);
            else
                $this->emailManager->sendEmail($email, 'pass_confirmation', $emailParams);
        }

        if (!empty($application['didit_session_id'])) {
            $this->diditService->deleteSession($application['didit_session_id']);
        }

        try {
            $this->database->delete('esn_membership_manager_in_progress_applications')
                ->condition('id', $application['id'])
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Unable to delete in progress application. @error', ['@error' => $e->getMessage()]);
        }

        $session->remove($this->getAuthenticationType() . '_email_authentication_data');
        $session->remove('application_form_saved_data');
        $session->remove('application_form_verification_data');

        $form_state->setRedirect('esn_membership_manager.apply_success');
    }

    /**
     * @noinspection PhpParameterByRefIsNotUsedAsReferenceInspection
     * @noinspection PhpUnusedParameterInspection
     */
    public function mobilityAjaxCallback(array &$form, FormStateInterface $form_state)
    {
        return $form['mobility_details']['dynamic_container'];
    }
}