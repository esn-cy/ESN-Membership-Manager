<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use DateTime;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Service\EmailManager;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ApplicationForm extends FormBase
{
    protected $configFactory;
    protected Connection $database;
    protected EmailManager $emailManager;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected ModuleHandlerInterface $moduleHandler;
    protected FileSystemInterface $fileSystem;
    protected FileRepositoryInterface $fileRepository;
    protected ClientInterface $httpClient;
    protected LoggerChannelInterface $logger;

    protected array $nationalities = [];

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        EmailManager                  $emailManager,
        EntityTypeManagerInterface    $entityTypeManager,
        ModuleHandlerInterface        $moduleHandler,
        FileSystemInterface           $fileSystem,
        FileRepositoryInterface       $fileRepository,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->emailManager = $emailManager;
        $this->entityTypeManager = $entityTypeManager;
        $this->moduleHandler = $moduleHandler;
        $this->fileSystem = $fileSystem;
        $this->fileRepository = $fileRepository;
        $this->httpClient = $httpClient;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        /** @var FileSystemInterface $fileSystem */
        $fileSystem = $container->get('file_system');

        /** @var FileRepositoryInterface $fileRepository */
        $fileRepository = $container->get('file.repository');

        /** @var ClientInterface $httpClient */
        $httpClient = $container->get('http_client');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configFactory,
            $database,
            $emailManager,
            $entityTypeManager,
            $moduleHandler,
            $fileSystem,
            $fileRepository,
            $httpClient,
            $loggerFactory
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

        $form['#attached']['library'][] = 'esn_membership_manager/application_form';
        $form['#attributes']['class'][] = 'esn-membership-manager-form';

        $form['#prefix'] = '<div id="application-form-wrapper">';
        $form['#suffix'] = '</div>';

        $schemeName = $moduleConfig->get('scheme_name');

        $session = $this->getRequest()->getSession();
        $savedData = $session->get('application_form_saved_data', []);
        $verificationData = $session->get('application_form_verification_data', []);

        $form_state->disableCache();

        $isCodeSent = !empty($verificationData['email_code_sent']) || $form_state->get('code_sent');
        $isCodeVerified = !empty($verificationData['email_code_verified']) || $form_state->get('code_verified');

        if (!$isCodeVerified) {
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

        $form['email']['actions_wrapper'] = [
            '#type' => 'container',
            '#attributes' => ['id' => 'verify-actions-wrapper'],
        ];

        $apiMessage = $form_state->get('api_message');
        if ($apiMessage) {
            $messageType = $form_state->get('api_message_type') ?? 'status';
            $form['email']['actions_wrapper']['message'] = [
                '#markup' => '<div class="messages messages--' . $messageType . '">' . $apiMessage . '</div>',
            ];
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

        $form['personal_details'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Personal Details'),
        ];

        $form['personal_details']['name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Name'),
            '#required' => TRUE,
        ];

        $form['personal_details']['surname'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Surname'),
            '#required' => TRUE,
        ];

        $form['personal_details']['nationality'] = [
            '#type' => 'select',
            '#title' => $this->t('Nationality'),
            '#options' => $this->getNationalities(),
            '#empty_option' => $this->t('- Select -'),
            '#required' => TRUE,
        ];

        $form['personal_details']['dob'] = [
            '#type' => 'date',
            '#title' => $this->t('Date of Birth'),
            '#required' => TRUE,
        ];

        $form['mobility_details'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Mobility & Status'),
        ];

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
            '#default_value' => $form_state->getValue('status'),
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
                    'file_validate_extensions' => ['pdf jpg jpeg png'],
                    'file_validate_size' => [5 * 1024 * 1024],
                ],
                '#required' => TRUE,
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
        ];

        $form['esncard']['esncard_requirements']['id_document'] = [
            '#type' => 'managed_file',
            '#title' => $this->t('Copy of ID or Passport'),
            '#description' => $this->t('Upload a scan of your ID or Passport for verification.'),
            '#upload_location' => 'membership://temp_uploads/',
            '#upload_validators' => [
                'file_validate_extensions' => ['jpg jpeg png pdf'],
            ],
            '#states' => [
                'required' => [
                    ':input[name="has_esncard"]' => ['checked' => TRUE],
                ],
            ],
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

    protected function getNationalities(): array
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
                    if (empty($data[1])) continue;
                    $val = trim($data[1]);
                    $nationalities[$val] = $val;
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

        $values = $form_state->getValues();
        $hasESNcard = (bool)($values['has_esncard'] ?? FALSE);

        if ($hasESNcard) {
            if (empty($values['id_document'])) {
                $form_state->setError($form['esncard']['esncard_requirements']['id_document'], $this->t('A copy of your ID or Passport is required for verification.'));
            }
            if (empty($values['face_photo'])) {
                $form_state->setError($form['esncard']['esncard_requirements']['face_photo'], $this->t('A passport style photo is required for the ESNcard.'));
            }
        }
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $values = $form_state->getValues();
        $hasESNcard = (bool)$values['has_esncard'];

        try {
            if (empty($values['proof_of_status'])) {
                $this->messenger()->addError($this->t('Proof of status is missing. Please select your status again and re-upload the file.'));
                return;
            }

            $proofFID = $this->saveFile($values['proof_of_status']);
            if ($hasESNcard) {
                $facePhotoFID = $this->saveFile($values['face_photo']);
                $idDocFID = $this->saveFile($values['id_document']);
            }
        } catch (Exception) {
            $this->messenger()->addError($this->t('An error occurred while saving your files. Please try again.'));
            if (!empty($proofFID)) {
                $this->deleteFile($proofFID);
            }
            if (!empty($facePhotoFID) && $hasESNcard) {
                $this->deleteFile($facePhotoFID);
            }
            if (!empty($idDocFID) && $hasESNcard) {
                $this->deleteFile($idDocFID);
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
            'other_train_traineeship' => 'Traineeship',
            'other_train_internship' => 'Internship',
            'other_train_apprenticeship' => 'Apprenticeship',
            'other_volunteer' => 'Volunteer (non-ESN)',
            'esn_volunteer' => 'ESN Volunteer',
            'esn_alumnus' => 'ESN Alumnus',
            'mobility_buddy' => 'Buddy',
            'mobility_mentor' => 'Mentor',
            'mobility_ambassador' => 'Mobility Ambassador'
        ];

        $fields = [
            'name' => trim($values['name']),
            'surname' => trim($values['surname']),
            'email' => trim($values['email']),
            'nationality' => $values['nationality'],
            'dob' => (new DateTime($values['dob']))->format('Y-m-d'),
            'mobility_status' => $statuses[$values['status']],
            'host_institution' => trim($values['host']),
            'proof_fid' => $proofFID,
            'approval_status' => 'Pending',
            'date_created' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
        ];

        if ($hasESNcard) {
            $fields['esncard'] = TRUE;
            $fields['face_photo_fid'] = $facePhotoFID;
            $fields['id_document_fid'] = $idDocFID;
        }

        try {
            $applicationID = $this->database->insert('esn_membership_manager_applications')->fields($fields)->execute();

            if ($applicationID) {
                $targetDirectory = 'membership://' . $applicationID;
                $this->moveFile($proofFID, $targetDirectory, 'status');
                if ($hasESNcard) {
                    $this->moveFile($facePhotoFID, $targetDirectory, 'face_photo');
                    $this->moveFile($idDocFID, $targetDirectory, 'id_document');
                }
            }
        } catch (Exception $e) {
            $this->messenger()->addError($this->t('Error saving application. Please try again.'));
            $this->logger->error($e->getMessage());
            return;
        }

        $emailParams = ['name' => $values['name']];

        if ($hasESNcard)
            $this->emailManager->sendEmail($values['email'], 'both_confirmation', $emailParams);
        else
            $this->emailManager->sendEmail($values['email'], 'pass_confirmation', $emailParams);

        $form_state->setRedirect('esn_membership_manager.apply_success');
    }

    /**
     * Helper to save managed files permanently.
     * @throws EntityStorageException
     */
    protected function saveFile($fid_array)
    {
        if (!empty($fid_array) && is_array($fid_array)) {
            $fid = reset($fid_array);

            $file = null;
            try {
                $file = $this->entityTypeManager->getStorage('file')->load($fid);
            } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
            }
            if ($file) {
                $file->setPermanent();
                $file->save();
                return $fid;
            }
        }
        return NULL;
    }

    /**
     * Helper to delete a file.
     */
    protected function deleteFile($fid): void
    {
        if (empty($fid)) {
            return;
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fid);
            $file?->delete();
        } catch (Exception $e) {
            $this->logger->error('Error deleting file @fid: @message', [
                '@fid' => $fid,
                '@message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Helper to move a file to a new directory.
     */
    protected function moveFile($fid, $directory, $renameTo = null): void
    {
        if (empty($fid)) {
            return;
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fid);
            if ($file) {
                if ($this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
                    $filename = $file->getFilename();
                    if ($renameTo) {
                        $extension = pathinfo($filename, PATHINFO_EXTENSION);
                        $filename = $renameTo . '.' . $extension;
                    }
                    $this->fileRepository->move($file, $directory . '/' . $filename);
                } else {
                    $this->logger->error('Failed to create or prepare directory: @directory', ['@directory' => $directory]);
                }
            }
        } catch (Exception $e) {
            $this->logger->error('Error moving file @fid to @directory: @message', [
                '@fid' => $fid,
                '@directory' => $directory,
                '@message' => $e->getMessage()
            ]);
        }
    }

    /** @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public function mobilityAjaxCallback(array &$form, FormStateInterface $form_state)
    {
        return $form['mobility_details']['dynamic_container'];
    }
}