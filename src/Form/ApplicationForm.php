<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RedirectCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\esn_accounts_api\Entity\Organisation;
use Drupal\esn_membership_manager\Service\DiditService;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\esn_membership_manager\Service\FileService;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApplicationForm extends FormBase
{
    protected $configFactory;
    protected Connection $database;
    protected EmailManager $emailManager;
    protected ModuleHandlerInterface $moduleHandler;
    protected FileService $fileService;
    protected ClientInterface $httpClient;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected LoggerChannelInterface $logger;
    protected DiditService $diditService;

    protected array $nationalities = [];

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        EmailManager                  $emailManager,
        ModuleHandlerInterface        $moduleHandler,
        FileService                   $fileService,
        ClientInterface               $httpClient,
        EntityTypeManagerInterface $entityTypeManager,
        LoggerChannelFactoryInterface $loggerFactory,
        DiditService                  $diditService,
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->moduleHandler = $moduleHandler;
        $this->fileService = $fileService;
        $this->httpClient = $httpClient;
        $this->entityTypeManager = $entityTypeManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->diditService = $diditService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var ClientInterface $httpClient */
        $httpClient = $container->get('http_client');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var DiditService $diditService */
        $diditService = $container->get('esn_membership_manager.didit_service');

        return new static(
            $configFactory,
            $database,
            $emailManager,
            $moduleHandler,
            $fileService,
            $httpClient,
            $entityTypeManager,
            $loggerFactory,
            $diditService
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_application_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $form['#prefix'] = '<div id="application-form-wrapper">';
        $form['#suffix'] = '</div>';

        $form['#cache'] = [
            'max-age' => 0,
        ];

        $form_state->disableCache();

        $schemeName = $moduleConfig->get('scheme_name');

        $session = $this->getRequest()->getSession();
        $savedData = $session->get('application_form_saved_data', []);
        $verificationData = $session->get('application_form_verification_data', []);

        $email = strtolower($session->get('verified_email') ?? $savedData['email'] ?? $form_state->getValue('email') ?? '');
        if (empty($email)) {
            $savedData = [];
            $verificationData = [];
            $session->remove('application_form_saved_data');
            $session->remove('application_form_verification_data');
        }

        $isCodeSent = !empty($verificationData['email_code_sent']) || $form_state->get('code_sent');
        $isCodeVerified = !empty($verificationData['email_code_verified']) || $form_state->get('code_verified');
        $emailExists = !empty($verificationData['email_exists']);

        if ($isCodeVerified) {
            $form['#attached']['library'][] = 'esn_membership_manager/application_form';
            $form['#attributes']['class'][] = 'esn-membership-manager-form';
        } else {
            $form['#attributes']['class'][] = 'esn-membership-manager-login-form';
            $form['#attached']['library'][] = 'esn_membership_manager/login_form';
        }

        $headerMarkup = '<h2>' . $this->t('Apply for an ESNcard / @scheme', ['@scheme' => $schemeName]) . '</h2>';
        if ($isCodeVerified) {
            $headerMarkup .=
                '<p>' . $this->t('The @scheme is your digital identifier. It verifies your status as a mobility participant and grants you access to exclusive events.', ['@scheme' => $schemeName]) . '</p>' .
                '<p>' . $this->t('The ESNcard is the official physical membership card of the Erasmus Student Network. It provides all the benefits of the @scheme, plus access to thousands of discounts at major brands and local businesses across Europe.', ['@scheme' => $schemeName]) . '</p>';
        }

        $form['header'] = [
            '#markup' => Markup::create($headerMarkup),
            '#weight' => -30,
        ];

        $form['email'] = [
            '#type' => 'fieldset',
        ];
        if ($isCodeVerified) {
            $form['email']['#title'] = $this->t('Email');
        }

        $form['email']['email'] = [
            '#type' => 'email',
            '#title' => $this->t('Email'),
            '#description' => $this->t('A verification code will be sent to this email address.'),
            '#required' => TRUE,
            '#default_value' => $form_state->getValue('email') ?? $savedData['email'] ?? '',
        ];
        if ($isCodeVerified || $emailExists) {
            $form['email']['email']['#disabled'] = TRUE;
        }

        $form['email']['actions_wrapper'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'verify-actions-wrapper'],
        ];

        $apiMessage = $form_state->get('api_message');
        if ($apiMessage) {
            $messageType = $form_state->get('api_message_type') ?? 'status';
            if ($emailExists) {
                $apiMessage = $this->t('You have already made an application with this email address.');
                $messageType = 'status';
            }
            if (!$isCodeVerified) {
                $form['email']['actions_wrapper']['message'] = [
                    '#markup' => '<div class="messages messages--' . $messageType . '">' . $apiMessage . '</div>',
                ];
            } else {
                $form['email']['actions_wrapper']['message'] = [
                    '#markup' => '<p class="alert alert-' . ($messageType == 'status' ? 'success' : 'warning') . '">' . $apiMessage . '</p>',
                ];
            }
        }

        if ($emailExists) {
            return $form;
        }

        if (!$isCodeSent && !$isCodeVerified) {
            $form['email']['actions_wrapper']['send_code'] = [
                '#type' => 'submit',
                '#value' => $this->t('Send Verification Email'),
                '#submit' => ['::sendCodeSubmit'],
                '#ajax' => [
                    'callback' => '::updateForm',
                    'wrapper' => 'application-form-wrapper',
                ],
                '#limit_validation_errors' => [['email']],
            ];
            return $form;
        } elseif ($isCodeSent && !$isCodeVerified) {
            $form['email']['actions_wrapper']['verification_code'] = [
                '#type' => 'textfield',
                '#title' => $this->t('Verification Code'),
                '#required' => TRUE,
                '#default_value' => $form_state->getValue('verification_code') ?? $savedData['verification_code'] ?? '',
            ];

            $form['email']['actions_wrapper']['verify_submit'] = [
                '#type' => 'submit',
                '#value' => $this->t('Verify Code'),
                '#submit' => ['::verifyCodeSubmit'],
                '#ajax' => [
                    'callback' => '::updateForm',
                    'wrapper' => 'application-form-wrapper',
                ],
                '#limit_validation_errors' => [['email'], ['verification_code']],
            ];
            return $form;
        }

        $diditEnabled = $moduleConfig->get('switch_didit') ?? false;
        $verificationStatus = null;
        $personalFieldsDisabled = false;

        if ($diditEnabled) {
            try {
                $application = $this->database->select('esn_membership_manager_in_progress_applications', 'i')
                    ->fields('i')
                    ->condition('email', $email)
                    ->execute()
                    ->fetchAssoc();

                if (!empty($application)) {
                    $diditStatus = $application['didit_status'] ?? null;
                    $diditSessionID = $application['didit_session_id'] ?? null;

                    if (!empty($diditSessionID) && !in_array($diditStatus, ['Approved', 'Declined', 'In Review'])) {
                        $diditSession = $this->diditService->getSession($diditSessionID);
                        $updatedStatus = $diditSession['status'] ?? null;

                        if (!empty($updatedStatus)) {
                            $updateFields = ['didit_status' => $updatedStatus];

                            if (in_array($updatedStatus, ['Approved', 'Declined', 'In Review'])) {
                                $verifiedDetails = $diditSession['id_verifications'][0];
                                $nationality = match ($verifiedDetails['nationality']) {
                                    'GBR' => 'British',
                                    'SHN' => 'St Helenian / Tristanian',
                                    'DOM' => 'Dominican',
                                    default => $this->getNationalities(true)[$verifiedDetails['nationality']]
                                };

                                $updateFields += [
                                    'name' => $verifiedDetails['first_name'],
                                    'surname' => $verifiedDetails['last_name'],
                                    'nationality' => $nationality,
                                    'dob' => $verifiedDetails['date_of_birth'],
                                ];
                            }

                            $this->database->update('esn_membership_manager_in_progress_applications')
                                ->fields($updateFields)
                                ->condition('didit_session_id', $diditSessionID)
                                ->execute();

                            $application = array_merge($application, $updateFields);
                            $diditStatus = $updatedStatus;
                        }
                    }

                    $verificationStatus = 'Other';
                    $verificationData = $session->get('application_form_verification_data', []);

                    if ($diditStatus == 'Approved') {
                        $verificationStatus = 'Success';

                        $savedData = $session->get('application_form_saved_data', []);
                        $savedData['name'] = $application['name'];
                        $savedData['surname'] = $application['surname'];
                        $savedData['nationality'] = $application['nationality'];
                        $savedData['dob'] = $application['dob'];
                        $session->set('application_form_saved_data', $savedData);

                        $verificationData['id_verified'] = true;
                    } elseif ($application['didit_status'] == 'Declined' || $application['didit_status'] == 'In Review') {
                        $verificationStatus = 'Failed';
                        $verificationData['id_verified'] = false;
                    }

                    if (!empty($application['didit_session_token'])) {
                        $diditVerifyLink = 'https://verify.didit.me/session/' . $application['didit_session_token'];
                        $verificationData['id_verification_link'] = $diditVerifyLink;
                    }

                    $session->set('application_form_verification_data', $verificationData);
                }
            } catch (Exception $e) {
                $this->logger->error('Unable to fetch in progress application data. @error.', ['@error' => $e->getMessage()]);
            }
        }

        $form['personal_details'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Personal Details'),
        ];

        if (!empty($verificationStatus)) {
            $personalFieldsDisabled = ($verificationStatus == 'Success');

            switch ($verificationStatus) {
                case 'Success':
                    $form['personal_details']['verified_success'] = [
                        '#markup' => Markup::create('<p class="alert alert-success">' . $this->t('You have successfully verified your identity.') . '</p>'),
                    ];
                    break;
                case 'Failed':
                    $form['personal_details']['verified_success'] = [
                        '#markup' => Markup::create('<p class="alert alert-warning">' . $this->t('Your identity verification has failed. Please fill in the following fields manually.') . '</p>'),
                    ];
                    break;
                case 'Other':
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
                    break;
            }
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
            '#options' => $this->getNationalities(),
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

        try {
            $organisations = $this->entityTypeManager->getStorage('esn_organisation');
            $sections = [];

            if (!($moduleConfig->get('section_mode') ?? null)) {
                $noID = $moduleConfig->get('national_organisation_id');
                /** @var Organisation $nationalOrganisation */
                $nationalOrganisation = $organisations->load($noID);
                if ($nationalOrganisation) {
                    /** @var Organisation[] $sectionEntities */
                    $sectionEntities = $organisations->loadByProperties(['type' => 'section', 'country_code' => $nationalOrganisation->getCountryCode()]);
                    foreach ($sectionEntities as $section) {
                        $title = $section->getTitle();
                        $sections[$title] = $title;
                    }
                    ksort($sections);
                }
            } else {
                $sectionName = $moduleConfig->get('organisation_name');
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
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
        }

        $statusOptions = [
            'Erasmus+ Programme' => [
                'erasmus_study' => $this->t('Study Exchange'),
                'erasmus_train_traineeship' => $this->t('Traineeship'),
                'erasmus_train_internship' => $this->t('Internship'),
                'erasmus_train_apprenticeship' => $this->t('Apprenticeship'),
                'erasmus_train_vet' => $this->t('VET'),
                'erasmus_mundus' => $this->t('Erasmus Mundus Joint Masters'),
            ],
            'European Solidarity Corps' => [
                'esc' => $this->t('European Solidarity Corps'),
            ],
            'International Full Degree Student' => [
                'international_undergrad' => $this->t('Undergraduate'),
                'international_postgrad' => $this->t('Postgraduate'),
            ],
            'Other Mobility Programme' => [
                'other_study' => $this->t('Study Exchange (Other)'),
                'other_train_traineeship' => $this->t('Traineeship (Other)'),
                'other_train_internship' => $this->t('Internship (Other)'),
                'other_train_apprenticeship' => $this->t('Apprenticeship (Other)'),
                'other_volunteer' => $this->t('Volunteer (non-ESN)'),
            ],
            'ESN' => [
                'esn_volunteer' => $this->t('ESN Volunteer'),
                'esn_alumnus' => $this->t('ESN Alumnus'),
            ],
            'Mobility Contributors' => [
                'mobility_buddy' => $this->t('Buddy'),
                'mobility_mentor' => $this->t('Mentor'),
                'mobility_ambassador' => $this->t('Mobility Ambassador'),
            ]
        ];

        $form['mobility_details']['status'] = [
            '#type' => 'select',
            '#title' => $this->t('Current Status'),
            '#options' => $statusOptions,
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $form_state->getValue('status') ?? $savedData['status'] ?? '',
            '#required' => TRUE,
            '#ajax' => [
                'callback' => '::mobilityAjaxCallback',
                'wrapper' => 'mobility-dynamic-wrapper',
            ],
        ];

        $form['mobility_details']['dynamic_container'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'mobility-dynamic-wrapper'],
        ];

        $status = $form_state->getValue('status') ?? $form_state->getUserInput()['status'] ?? NULL;

        $organizationLabel = $this->t('Host Institution');
        $proofLabelText = $this->t('Appropriate Certification');
        $showDynamicFields = !empty($status);

        if ($showDynamicFields) {
            if (str_contains($status, '_study') || str_contains($status, '_mundus') || str_contains($status, '_vet')) {
                $organizationLabel = $this->t('Host University');
                $proofLabelText = str_starts_with($status, 'other') ? $this->t('Appropriate Certification') : $this->t('Learning Agreement');
            } elseif (str_contains($status, '_train_')) {
                $organizationLabel = $this->t('Host Organization');
                $proofLabelText = str_starts_with($status, 'other') ? $this->t('Appropriate Certification') : $this->t('Traineeship Certificate');
            } elseif ($status == 'esc') {
                $organizationLabel = $this->t('Host Organization');
                $proofLabelText = $this->t('ESC Certificate');
            } elseif (str_starts_with($status, 'international_')) {
                $organizationLabel = $this->t('University');
                $proofLabelText = $this->t('International Application / Certificate of Studies');
            } elseif (str_starts_with($status, 'esn_')) {
                $organizationLabel = $this->t('ESN Section');
                $proofLabelText = $this->t('ESN Certificate / Membership Proof');
            } elseif (str_starts_with($status, 'mobility_')) {
                $organizationLabel = $this->t('University / Organization');
                $proofLabelText = $this->t('Appropriate Certification');
            }
        }

        if ($showDynamicFields) {
            $form['mobility_details']['dynamic_container']['host'] = [
                '#type' => 'textfield',
                '#description' => 'You need to enter institution that\'s hosting you here, not the one from your country of origin.',
                '#title' => $organizationLabel,
                '#required' => TRUE,
                '#default_value' => $form_state->getValue('host') ?? $savedData['host'] ?? '',
            ];

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
                '#default_value' => $form_state->getValue('proof_of_status') ?? $savedData['proof_of_status'] ?? '',
            ];
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

        $form['actions']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Submit Application'),
            '#button_type' => 'primary',
        ];

        $svg = '';
        $path = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/images/logo.svg';
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

    protected function getNationalities(bool $getISO = false): array
    {
        if (!empty($this->nationalities)) {
            return $this->nationalities;
        }

        try {
            $path = $this->moduleHandler->getModule('esn_membership_manager')->getPath() . '/assets/data/nationalities.csv';
        } catch (Exception) {
            $this->nationalities = [];
            return [];
        }

        $nationalities = [];

        if (file_exists($path)) {
            if (($handle = fopen($path, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",", "\"", "\\")) !== FALSE) {
                    if ($getISO) {
                        if (empty($data[0]) || empty($data[1])) continue;
                        $nationalities[trim($data[0])] = trim($data[1]);
                    } else {
                        if (empty($data[1])) continue;
                        $val = trim($data[1]);
                        $nationalities[$val] = $val;
                    }
                }
                fclose($handle);
            }
        }
        $this->nationalities = $nationalities;
        return $nationalities;
    }

    /**
     * Submit handler for sending the verification code.
     */
    public function sendCodeSubmit(array &$form, FormStateInterface $form_state): void
    {
        $email = $form_state->getValue('email');

        $emailExists = $this->database->select('esn_membership_manager_applications', 'a')
                ->condition('email', $email)
                ->countQuery()
                ->execute()
                ->fetchField() != 0;
        if ($emailExists) {
            $form_state->set('api_message', $this->t('You have already made an application with this email address.'));
            $form_state->set('api_message_type', 'status');

            $session = $this->getRequest()->getSession();
            $verificationData = $session->get('application_form_verification_data', []);
            $verificationData['email_exists'] = TRUE;
            $session->set('application_form_verification_data', $verificationData);

            $savedData = $session->get('application_form_saved_data', []);
            $savedData['email'] = $email;
            $session->set('application_form_saved_data', $savedData);

            $form_state->setRebuild();
            return;
        }

        try {
            $response = $this->httpClient->post(
                Url::fromRoute(
                    'esn_membership_manager.authentication_code',
                    ['type' => 'register'],
                    ['absolute' => TRUE]
                )->toString(),
                [
                    'json' => ['email' => $email]
                ]
            );

            $body = json_decode((string)$response->getBody(), TRUE);

            if (isset($body['error'])) {
                $form_state->set('api_message', $body['error']);
                $form_state->set('api_message_type', 'error');
            } else {
                $form_state->set('api_message', $body['message'] ?? $this->t('Verification email sent.'));
                $form_state->set('api_message_type', 'status');
                $form_state->set('code_sent', TRUE);

                $session = $this->getRequest()->getSession();
                $verificationData = $session->get('application_form_verification_data', []);
                $verificationData['email_code_sent'] = TRUE;
                $session->set('application_form_verification_data', $verificationData);

                $savedData = $session->get('application_form_saved_data', []);
                $savedData['email'] = $email;
                $session->set('application_form_saved_data', $savedData);
            }
        } catch (GuzzleException) {
            $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
            $form_state->set('api_message_type', 'error');
        }

        $form_state->setRebuild();
    }

    public function verifyCodeSubmit(array &$form, FormStateInterface $form_state): void
    {
        $email = $form_state->getValue('email');
        $code = $form_state->getValue('verification_code');

        try {
            $response = $this->httpClient->post(
                Url::fromRoute(
                    'esn_membership_manager.authentication_verify',
                    ['type' => 'register'],
                    ['absolute' => TRUE]
                )->toString(),
                [
                    'json' => [
                        'email' => $email,
                        'code' => $code,
                    ]
                ]
            );

            $body = json_decode((string)$response->getBody(), TRUE);

            if (isset($body['error'])) {
                $form_state->set('api_message', $body['error']);
                $form_state->set('api_message_type', 'error');
            } else {
                try {
                    $this->database->merge('esn_membership_manager_in_progress_applications')
                        ->key('email', $email)
                        ->fields([
                            'date_created' => (new DrupalDateTime())->format('Y-m-d H:i:s')
                        ])
                        ->execute();
                } catch (Exception $e) {
                    $this->logger->error('Unable to create in progress application. @error.', ['@error' => $e->getMessage()]);
                    $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
                    $form_state->set('api_message_type', 'error');
                    $form_state->setRebuild();
                    return;
                }

                $form_state->set('api_message', $body['message'] ?? $this->t('Email address verified.'));
                $form_state->set('api_message_type', 'status');
                $form_state->set('code_verified', TRUE);

                $session = $this->getRequest()->getSession();
                $verificationData = $session->get('application_form_verification_data', []);
                $verificationData['email_code_verified'] = TRUE;
                $session->set('application_form_verification_data', $verificationData);

                $savedData = $session->get('application_form_saved_data', []);
                $savedData['email'] = $email;
                $savedData['verification_code'] = $code;
                $session->set('application_form_saved_data', $savedData);
            }
        } catch (GuzzleException) {
            $form_state->set('api_message', $this->t('There was an issue processing your request. Please try again later.'));
            $form_state->set('api_message_type', 'error');
        }

        $form_state->setRebuild();
    }

    public function redirectToDidit(array &$form, FormStateInterface $form_state): AjaxResponse
    {
        $response = new AjaxResponse();

        $session = $this->getRequest()->getSession();
        $verificationData = $session->get('application_form_verification_data', []);
        $verificationLink = $verificationData['id_verification_link'] ?? null;

        if (empty($verificationLink)) {
            $email = $session->get('application_form_saved_data', [])['email'];
            $verificationLink = $this->diditService->createVerificationSession($email);
        }

        $response->addCommand(new RedirectCommand($verificationLink));

        return $response;
    }

    /**
     * AJAX callback to update the actions' wrapper.
     */
    public function updateForm(array &$form, FormStateInterface $form_state): array
    {
        return $form;
    }


    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);

        $session = $this->getRequest()->getSession();
        $verificationData = $session->get('application_form_verification_data', []);
        $isVerifiedEmail = !empty($verificationData['email_code_verified']);
        $isVerifiedID = !empty($verificationData['id_verified']);

        if (!$isVerifiedEmail) {
            return;
        }

        $values = $form_state->getValues();
        $hasESNcard = $form_state->getValue('has_esncard', false);

        if (empty($values['proof_of_status'])) {
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

        $email = $session->get('verified_email') ?? $savedData['email'] ?? $form_state->getValue('email');

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

        $filesExpected = ['proof_of_status'];
        if (!$isVerifiedID) {
            $filesExpected[] = 'id_document';
        }
        if ($hasESNcard) {
            $filesExpected[] = 'face_photo';
        }

        $filesSaved = [];
        foreach ($filesExpected as $fileKey) {
            $fileID = $values[$fileKey][0] ?? null;

            if ($this->fileService->saveFile($fileID)) {
                $filesSaved[] = $fileKey;
            }
        }

        if (count($filesExpected) != count($filesSaved)) {
            $this->messenger()->addError($this->t('An error occurred while saving your files. Please try again.'));
            foreach ($filesSaved as $savedFile) {
                $this->fileService->deleteFile($values[$savedFile][0] ?? null);
            }
            return;
        }

        $statuses = [
            'erasmus_study' => 'Study Exchange',
            'erasmus_train_traineeship' => 'Traineeship',
            'erasmus_train_internship' => 'Internship',
            'erasmus_train_apprenticeship' => 'Apprenticeship',
            'erasmus_train_vet' => 'VET',
            'erasmus_mundus' => 'Erasmus Mundus Joint Masters',
            'esc' => 'European Solidarity Corps',
            'international_undergrad' => 'Undergraduate',
            'international_postgrad' => 'Postgraduate',
            'other_study' => 'Study Exchange (Other)',
            'other_train_traineeship' => 'Traineeship (Other)',
            'other_train_internship' => 'Internship (Other)',
            'other_train_apprenticeship' => 'Apprenticeship (Other)',
            'other_volunteer' => 'Volunteer (non-ESN)',
            'esn_volunteer' => 'ESN Volunteer',
            'esn_alumnus' => 'ESN Alumnus',
            'mobility_buddy' => 'Buddy',
            'mobility_mentor' => 'Mentor',
            'mobility_ambassador' => 'Mobility Ambassador'
        ];

        try {
            $dateOfBirth = (new DrupalDateTime($isVerifiedID ? $application['dob'] : $values['dob']))->format('Y-m-d');
        } catch (Exception) {
            $this->messenger()->addError($this->t('Your selected date of birth is invalid. Please try again.'));
            return;
        }

        $fields = [
            'name' => trim($isVerifiedID ? $application['name'] : $values['name']),
            'surname' => trim($isVerifiedID ? $application['surname'] : $values['surname']),
            'email' => trim($email),
            'nationality' => trim($isVerifiedID ? $application['nationality'] : $values['nationality']),
            'dob' => $dateOfBirth,
            'section' => trim($values['section'] ?? 'Unknown Section'),
            'mobility_status' => $statuses[$values['status']],
            'host_institution' => trim($values['host']),
            'proof_fid' => $values['proof_of_status'][0],
            'approval_status' => 'Pending',
            'verified_email' => 1,
            'verified_id' => (int)$isVerifiedID,
            'date_created' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
        ];

        if ($isVerifiedID) {
            $pdfData = $this->diditService->getPDF($application['didit_session_id']);
            $values['id_document'][0] = $this->fileService->createFile($pdfData, "membership://temp_uploads", "id_document_{$application['id']}.pdf");
        }
        $fields['id_document_fid'] = $values['id_document'][0];
        if ($hasESNcard) {
            $fields['esncard'] = 1;
            $fields['face_photo_fid'] = $values['face_photo'][0];
        }

        try {
            $applicationID = $this->database->insert('esn_membership_manager_applications')->fields($fields)->execute();

            if ($applicationID) {
                $targetDirectory = 'membership://' . $applicationID;
                $this->fileService->moveFile($values['proof_of_status'][0], $targetDirectory, 'status');
                $this->fileService->moveFile($values['id_document'][0], $targetDirectory, 'id_document');
                if ($hasESNcard) {
                    $this->fileService->moveFile($values['face_photo'][0], $targetDirectory, 'face_photo');
                }
            }
        } catch (Exception $e) {
            $this->fileService->deleteFile($values['proof_of_status'][0] ?? null);
            $this->fileService->deleteFile($values['id_document'][0] ?? null);
            if ($hasESNcard) {
                $this->fileService->deleteFile($values['face_photo'][0] ?? null);
            }
            $this->messenger()->addError($this->t('Error saving application. Please try again.'));
            $this->logger->error($e->getMessage());
            return;
        }

        $emailParams = ['name' => trim($isVerifiedID ? $savedData['name'] : $values['name'])];

        if ($hasESNcard)
            $this->emailManager->sendEmail($email, 'both_confirmation', $emailParams);
        else
            $this->emailManager->sendEmail($email, 'pass_confirmation', $emailParams);

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

        $session->remove('application_form_saved_data');
        $session->remove('application_form_verification_data');

        $form_state->setRedirect('esn_membership_manager.apply_success');
    }

    /** @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public function mobilityAjaxCallback(array &$form, FormStateInterface $form_state)
    {
        return $form['mobility_details']['dynamic_container'];
    }
}