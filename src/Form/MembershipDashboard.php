<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use DateInterval;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\ModuleSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Utility\Nationalities;
use Exception;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class MembershipDashboard extends AuthenticatedFormBase
{
    protected ModuleHandlerInterface $moduleHandler;
    protected FileService $fileService;
    protected ?Nationalities $nationalities = null;

    public function __construct(
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory,
        ModuleHandlerInterface        $moduleHandler,
        FileService                   $fileService,
    )
    {
        parent::__construct($database, $entityTypeManager, $httpClient, $loggerFactory);
        $this->moduleHandler = $moduleHandler;
        $this->fileService = $fileService;
    }

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

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        return new static(
            $database,
            $entityTypeManager,
            $httpClient,
            $loggerFactory,
            $moduleHandler,
            $fileService,
        );
    }

    /**
     * @inheritDoc
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_dashboard';
    }

    /**
     * {@inheritDoc}
     */
    protected function getAuthenticationType(): string
    {
        return 'login';
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
        return Markup::create('<h2>' . $this->t('Manage your Application') . '</h2>');
    }

    /**
     * @inheritDoc
     * @noinspection HtmlUnknownTarget
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $moduleSettings = new ModuleSettings($this->configFactory());

        $form = parent::buildForm($form, $form_state);
        if ($this->isDialogAdded) {
            return $form;
        }

        $form['#attributes']['class'][] = 'esn-membership-manager-dashboard';
        $form['#attached']['library'][] = 'esn_membership_manager/membership_dashboard';

        /** @var ApplicationStorage $storage */
        try {
            $storage = $this->entityTypeManager->getStorage('membership_application');
        } catch (Exception) {
            $this->messenger()->addError($this->t('Could not retrieve your application. Please try again later.'));
            return $form;
        }

        $application = $storage->getByEmailAddress($this->authenticatedEmail);
        if (empty($application)) {
            $this->messenger()->addError($this->t('Could not retrieve your application. Please try again later.'));
            return $form;
        }

        $form['overview'] = [
            '#type' => 'container',
        ];

        $form['overview']['header'] = [
            '#markup' => '
        <div class="header">
            <div>
                <h2>Welcome back, ' . $application->getValue(ApplicationField::Name) . '!</h2>
                <p>Manage your ESN membership and application status here.</p>
            </div>
        </div>',
        ];

        $form['hidden_actions'] = [
            '#type' => 'container',
            '#attributes' => [
                'style' => 'display: none;'
            ],
        ];

        $rejectedDocuments = [];
        $pendingDocuments = [];

        if ($application->getApprovalStatus() == 'Rejected') {
            $reasons = $application->getRejectionReasons();
            $formatedReasons = [];
            $eligibilityIssues = [];
            $hasOther = false;

            if (!empty($reasons)) {
                foreach ($reasons as $reason) {
                    if ($reason['category'] == 'Eligibility') {
                        $eligibilityIssues[] = $reason;
                        continue;
                    }
                    if (!empty($eligibilityIssues)) {
                        continue;
                    }
                    if ($reason['category'] == 'Other' && $reason['issue'] == 'Other') {
                        $hasOther = true;
                        continue;
                    }
                    if (in_array($reason['category'], ['Status', 'Identity', 'Photo'])) {
                        $rejectedDocuments[] = $reason['category'];
                        $reasonString = 'the uploaded ';
                        switch ($reason['category']) {
                            case 'Status':
                                $reasonString .= 'Proof of Status ';
                                break;
                            case 'Identity':
                                $reasonString .= 'Identity Document ';
                                break;
                            case 'Photo':
                                $reasonString .= 'Face Photo ';
                                break;
                        }
                        $reasonString .= 'is ' . strtolower($reason['issue']);
                        $formatedReasons[] = $reasonString;
                    }
                }
            }

            if (!empty($eligibilityIssues)) {
                if (!empty(array_filter($eligibilityIssues, function ($reason) {
                    return $reason['issue'] == 'Local';
                }))) {
                    $alertText = 'Your application has been rejected as you are a local student.';
                    $isRejectionFixable = false;
                } else {
                    $alertText = 'Your application has been rejected as you do not match any eligible categories. Please update your Proof of Status if you think that this has been an error.';
                    $isRejectionFixable = true;
                }
            } elseif (empty($reasons) || (sizeof($reasons) == 1 && $hasOther)) {
                $alertText = 'Your application has been rejected. Please review your information and documents and resubmit your application.';
                $isRejectionFixable = true;
            } else {
                $alertText = 'Your application has been rejected as ' . join(', ', $formatedReasons) . '. Please fix those issues to proceed with your application.';
                $isRejectionFixable = true;
            }

            $form['overview']['alert'] = [
                '#type' => 'container',
                '#attributes' => [
                    'class' => ['alert', 'alert-error']
                ],
                'content' => [
                    '#markup' => '
                <div>
                    <div>
                        <span class="material-symbols-outlined" style="font-variation-settings: \'FILL\' 1;">error</span>
                        <h3>Application Needs Attention</h3>
                    </div>
                    <p>' . $alertText . '</p>
                </div>',
                ],
            ];

            if ($isRejectionFixable) {
                $form['overview']['alert']['action'] = [
                    '#type' => 'link',
                    '#title' => $this->t('Review Details'),
                    '#url' => Url::fromRoute('<current>', [], ['fragment' => 'application']),
                    '#attributes' => [
                        'class' => ['error-button']
                    ],
                ];
            }
        } elseif ($application->getApprovalStatus() == 'Pending') {
            $reasons = $application->getPendingReasons();

            foreach ($reasons as $reason) {
                if (in_array($reason['category'], ['Status', 'Identity', 'Photo'])) {
                    $pendingDocuments[] = $reason['category'];
                }
            }
        }

        $isApproved = in_array($application->getApprovalStatus(), ['Approved', 'Paid', 'Issued', 'Delivered']);

        if ($isApproved && ($moduleSettings->getGoogleWalletSwitch() || $moduleSettings->getAppleWalletSwitch())) {
            $buttonText = 'Add to Wallet';
            $buttonHref = '#wallet';
        } elseif ($application->getApprovalStatus() == 'Approved' && $application->getValue(ApplicationField::HasESNcard)) {
            $buttonText = 'Pay for ESNcard';
            $buttonHref = $application->getValue(ApplicationField::PaymentLink);
        } elseif (!$application->getValue(ApplicationField::HasESNcard)) {
            $buttonText = 'Get an ESN card';
            $buttonHref = '#esncard';
        } else {
            $buttonText = null;
            $buttonHref = null;
        }

        $form['overview']['stats_grid'] = [
            '#type' => 'inline_template',
            '#template' => '
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <span>MEMBERSHIP STATUS</span>
                        </div>
                        <div class="stat-value">{{ approval_status }}</div>
                        <div class="stat-subtext">{% if not approval_status == \'Active\' %}Action required{% endif %}</div>
                    </div>
                    <div class="stat-divided-card">
                        <div>
                            <div class="stat-header">
                                <span>PASS VALID UNTIL</span>
                            </div>
                            <div class="stat-value{% if date_approved == \'--/--/----\' %} text-muted{% endif %}">{{ date_approved }}</div>
                            <div class="stat-subtext">{% if date_approved == \'--/--/----\' %}Awaiting Approval{% endif %}</div>
                        </div>
                        {% if has_esncard %}
                        <div class="stat-divider"></div>
                        <div>
                            <div class="stat-header">
                                <span>ESNcard VALID UNTIL</span>
                            </div>
                            <div class="stat-value{% if date_approved == \'--/--/----\' %} text-muted{% endif %}">{{ date_paid }}</div>
                            <div class="stat-subtext">{% if date_paid == \'--/--/----\' %}Awaiting Payment{% endif %}</div>
                        </div>
                        {% endif %}
                    </div>
                    <div class="stat-card stat-action-card">
                        <div class="stat-header">
                            <span>QUICK ACTIONS</span>
                        </div>
                        {% if button_text is not empty and button_href is not empty %}
                        <a href="{{ button_href }}" class="secondary-button">{{ button_text }}</a>
                        {% else %}
                        <span class="disabled-secondary-button">No Action Available</span>
                        {% endif %}
                    </div>
                </div>
            ',
            '#context' => [
                'approval_status' => $isApproved ? 'Active' : $application->getApprovalStatus(),
                'date_approved' => !empty($application->getDateApproved()) ? $application->getDateApproved()->add(new DateInterval('P1Y'))->format('d/m/Y') : '--/--/----',
                'has_esncard' => $application->getValue(ApplicationField::HasESNcard) ?? false,
                'date_paid' => !empty($application->getDatePaid()) ? $application->getDatePaid()->add(new DateInterval('P1Y'))->format('d/m/Y') : '--/--/----',
                'button_text' => $buttonText,
                'button_href' => $buttonHref,
            ],
        ];

        $form['application_section'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['section'],
                'id' => 'application',
            ],
        ];

        $form['application_section']['title'] = [
            '#markup' => '<h2>Application Details</h2>',
        ];

        $form['application_section']['personal_info'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['panel']
            ],
        ];

        if (empty($this->nationalities)) {
            $this->nationalities = new Nationalities($this->moduleHandler);
        }

        $isPendingID = in_array('Identity', $pendingDocuments) && $application->getApprovalStatus() == 'Pending';
        $isVerifiedID = $application->getValue(ApplicationField::HasVerifiedID) ?? false;
        $isApprovedID = !in_array('Identity', $rejectedDocuments);

        $form_state->set('is_approved_identity', $isApprovedID);

        $form['application_section']['personal_info']['header'] = [
            '#markup' => '
                <div class="panel-header">
                    <h3>Personal Information</h3>
                    ' . (!$isApprovedID ? '<a href="#" class="panel-button-text" data-submit-id="edit-personal-submit">Edit</a>' : '') . '
                </div>',
        ];

        $form['application_section']['personal_info']['grid'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['panel-body', 'info-grid']
            ],
        ];

        $form['application_section']['personal_info']['grid']['name'] = [
            '#type' => 'textfield',
            '#default_value' => $application->getValue(ApplicationField::Name),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Name</label>',
            '#suffix' => '</div>',
        ];

        $form['application_section']['personal_info']['grid']['surname'] = [
            '#type' => 'textfield',
            '#default_value' => $application->getValue(ApplicationField::Surname),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Surname</label>',
            '#suffix' => '</div>',
        ];

        $form['application_section']['personal_info']['grid']['nationality'] = [
            '#type' => 'select',
            '#options' => $this->nationalities->get(),
            '#default_value' => $application->getValue(ApplicationField::Nationality),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly',
                'tabindex' => '-1'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Nationality</label>',
            '#suffix' => '</div>',
        ];

        $form['application_section']['personal_info']['grid']['date_of_birth'] = [
            '#type' => 'date',
            '#default_value' => $application->getDateOfBirth()->format('Y-m-d'),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Date of Birth</label>',
            '#suffix' => '</div>',
        ];

        $form['hidden_actions']['submit_personal_info'] = [
            '#type' => 'submit',
            '#value' => 'Save Personal Info',
            '#submit' => ['::submitPersonalInfo'],
            '#attributes' => [
                'id' => 'edit-personal-submit'
            ],
        ];

        $form['application_section']['personal_info']['document_list_top'] = [
            '#markup' => '
                <div class="document-list">
                    <div class="document-item">
                        <div class="document-info">
                            <div class="icon-wrap' . ((!$isPendingID && !$isVerifiedID && !$isApprovedID) ? ' icon-error' : '') . '"><span class="material-symbols-outlined">badge</span></div>
                            <div>
                                <div class="document-title">Identity Document</div>
                                <div class="' . (($isPendingID || $isVerifiedID || $isApprovedID) ? 'text-muted' : 'text-error') . ' text-sm">' . ($isVerifiedID ? 'Identity Verified Online' : (($isApprovedID || $isPendingID) ? 'Document Uploaded' : 'Replacement Required')) . '</div>
                            </div>
                        </div>',
        ];

        switch ($application->getApprovalStatus()) {
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'Pending':
                if ($isPendingID) {
                    $form['application_section']['personal_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-warning">
                            <span class="material-symbols-outlined">schedule</span> 
                            In Review
                        </span>
                    ',
                    ];
                    break;
                }
            case 'Approved':
            case 'Paid':
            case 'Issued':
            case 'Delivered':
                $badgeText = $isVerifiedID ? 'Verified' : 'Approved';
                $form['application_section']['personal_info']['status_badge'] = [
                    '#markup' => '
                    <span class="badge badge-success">
                        <span class="material-symbols-outlined">check_circle</span> 
                        ' . $badgeText . '
                    </span>
                    ',
                ];
                break;
            case 'Rejected':
                if ($isApprovedID) {
                    $form['application_section']['personal_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-warning">
                            <span class="material-symbols-outlined">warning</span> 
                            Pending Issues
                        </span>
                        ',
                    ];
                } elseif ($isRejectionFixable ?? false) {
                    $form['application_section']['personal_info']['identity_upload'] = [
                        '#type' => 'managed_file',
                        '#title_display' => 'invisible',
                        '#upload_location' => 'membership://temp_uploads/',
                        '#upload_validators' => [
                            'file_validate_extensions' => ['jpg jpeg png pdf'],
                            'file_validate_size' => [8 * 1024 * 1024]
                        ],
                        '#attributes' => [
                            'class' => ['auto-submit-file'],
                        ],
                        '#process' => [
                            ['\Drupal\file\Element\ManagedFile', 'processManagedFile'],
                            [$this, 'processFileUploadAttributes'],
                        ],
                    ];

                    $form['hidden_actions']['submit_identity_file'] = [
                        '#type' => 'submit',
                        '#value' => 'Save Identity File',
                        '#submit' => ['::submitIdentityFile'],
                        '#attributes' => [
                            'id' => 'edit-identity-upload-submit'
                        ],
                    ];
                } else {
                    $form['application_section']['personal_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-error">
                            <span class="material-symbols-outlined">cancel</span> 
                            Rejected
                        </span>
                        ',
                    ];
                }
                break;
        }

        $form['application_section']['personal_info']['content_bottom'] = [
            '#markup' => '</div></div>',
        ];

        $form['application_section']['mobility_info'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['panel']
            ],
        ];

        $isPendingStatus = in_array('Status', $pendingDocuments) && $application->getApprovalStatus() == 'Pending';
        $isVerifiedStatus = $application->getValue(ApplicationField::HasVerifiedStatus) ?? false;
        $isApprovedStatus = !in_array('Status', $rejectedDocuments);

        $form_state->set('is_approved_status', $isApprovedStatus);

        $form['application_section']['mobility_info']['header'] = [
            '#markup' => '
                <div class="panel-header">
                    <h3>Mobility Information</h3>
                    ' . (!$isApprovedStatus ? '<a href="#" class="panel-button-text" data-submit-id="edit-mobility-submit">Edit</a>' : '') . '
                </div>',
        ];

        $form['application_section']['mobility_info']['grid'] = [
            '#type' => 'container',
            '#attributes' => [
                'class' => ['panel-body', 'info-grid']
            ],
        ];

        $form['application_section']['mobility_info']['grid']['mobility_status'] = [
            '#type' => 'textfield',
            '#default_value' => $application->getValue(ApplicationField::MobilityStatus),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Mobility Status</label>',
            '#suffix' => '</div>',
        ];

        $form['application_section']['mobility_info']['grid']['host_institution'] = [
            '#type' => 'textfield',
            '#default_value' => $application->getValue(ApplicationField::HostInstitution),
            '#attributes' => [
                'class' => ['info-value'],
                'readonly' => 'readonly'
            ],
            '#theme_wrappers' => [],
            '#prefix' => '<div class="info-item"><label>Host Institution</label>',
            '#suffix' => '</div>',
        ];

        $form['hidden_actions']['submit_mobility_info'] = [
            '#type' => 'submit',
            '#value' => 'Save Mobility Info',
            '#submit' => ['::submitMobilityFields'],
            '#attributes' => [
                'id' => 'edit-mobility-submit'
            ],
        ];

        $form['application_section']['mobility_info']['document_list_start'] = [
            '#markup' => '
                <div class="document-list">
                    <div class="document-item">
                        <div class="document-info">
                            <div class="icon-wrap' . ((!$isPendingStatus && !$isVerifiedStatus && !$isApprovedStatus) ? ' icon-error' : '') . '"><span class="material-symbols-outlined">badge</span></div>
                            <div>
                                <div class="document-title">Proof of Status</div>
                                <div class="' . (($isPendingStatus || $isVerifiedStatus || $isApprovedStatus) ? 'text-muted' : 'text-error') . ' text-sm">' . ($isVerifiedStatus ? 'Status Verified Online' : (($isApprovedStatus || $isPendingStatus) ? 'Document Uploaded' : 'Replacement Required')) . '</div>
                            </div>
                        </div>',
        ];

        switch ($application->getApprovalStatus()) {
            /** @noinspection PhpMissingBreakStatementInspection */
            case 'Pending':
                if ($isPendingStatus) {
                    $form['application_section']['mobility_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-warning">
                            <span class="material-symbols-outlined">schedule</span> 
                            In Review
                        </span>
                    ',
                    ];
                    break;
                }
            case 'Approved':
            case 'Paid':
            case 'Issued':
            case 'Delivered':
                $badgeText = $isVerifiedStatus ? 'Verified' : 'Approved';
                $form['application_section']['mobility_info']['status_badge'] = [
                    '#markup' => '
                    <span class="badge badge-success">
                        <span class="material-symbols-outlined">check_circle</span> 
                        ' . $badgeText . '
                    </span>
                    ',
                ];
                break;
            case 'Rejected':
                if ($isApprovedStatus) {
                    $form['application_section']['mobility_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-warning">
                            <span class="material-symbols-outlined">warning</span> 
                            Pending Issues
                        </span>
                        ',
                    ];
                } elseif ($isRejectionFixable ?? false) {
                    $form['application_section']['mobility_info']['status_upload'] = [
                        '#type' => 'managed_file',
                        '#title_display' => 'invisible',
                        '#upload_location' => 'membership://temp_uploads/',
                        '#upload_validators' => [
                            'file_validate_extensions' => ['jpg jpeg png pdf'],
                            'file_validate_size' => [8 * 1024 * 1024]
                        ],
                        '#attributes' => [
                            'class' => ['auto-submit-file'],
                        ],
                        '#process' => [
                            ['\Drupal\file\Element\ManagedFile', 'processManagedFile'],
                            [$this, 'processFileUploadAttributes'],
                        ],
                    ];

                    $form['hidden_actions']['submit_status_file'] = [
                        '#type' => 'submit',
                        '#value' => 'Save Status File',
                        '#submit' => ['::submitStatusFile'],
                        '#attributes' => [
                            'id' => 'edit-status-upload-submit'
                        ],
                    ];
                } else {
                    $form['application_section']['mobility_info']['status_badge'] = [
                        '#markup' => '
                        <span class="badge badge-error">
                            <span class="material-symbols-outlined">cancel</span> 
                            Rejected
                        </span>
                        ',
                    ];
                }
                break;
        }

        $form['application_section']['mobility_info']['content_bottom'] = [
            '#markup' => '</div></div>',
        ];

        if ($application->getValue(ApplicationField::HasESNcard)) {
            $form['application_section']['esncard_photo'] = [
                '#type' => 'container',
                '#attributes' => [
                    'class' => ['panel']
                ],
            ];

            $isPendingPhoto = in_array('Photo', $pendingDocuments) && $application->getApprovalStatus() == 'Pending';
            $isApprovedPhoto = !in_array('Photo', $rejectedDocuments);

            $form['application_section']['esncard_photo']['content'] = [
                '#type' => 'inline_template',
                '#template' => '
                <div class="panel-header">
                    <h3>ESNcard Photo</h3>
                </div>
                <div class="document-list">
                    <div class="document-item" style="border-top: none">
                        <div class="document-info">
                            <div class="icon-wrap{% if not is_pending and not is_approved %} icon-error{% endif %}"><span class="material-symbols-outlined">badge</span></div>
                            <div>
                                <div class="document-title">ESNcard Photo</div>
                                <div class="{% if is_pending or is_approved %}text-muted{% else %}text-error{% endif %} text-sm">{% if is_approved or is_pending %}Photo Uploaded{% else %}Replacement Required{% endif %}</div>
                            </div>
                        </div>
            ',
                '#context' => [
                    'is_pending' => $isPendingPhoto,
                    'is_approved' => $isApprovedPhoto,
                ],
            ];

            switch ($application->getApprovalStatus()) {
                /** @noinspection PhpMissingBreakStatementInspection */
                case 'Pending':
                    if ($isPendingPhoto) {
                        $form['application_section']['esncard_photo']['status_badge'] = [
                            '#markup' => '
                            <span class="badge badge-warning">
                                <span class="material-symbols-outlined">schedule</span> 
                                In Review
                            </span>
                        ',
                        ];
                        break;
                    }
                case 'Approved':
                case 'Paid':
                case 'Issued':
                case 'Delivered':
                    $form['application_section']['esncard_photo']['status_badge'] = [
                        '#markup' => '
                    <span class="badge badge-success">
                        <span class="material-symbols-outlined">check_circle</span> 
                        Approved
                    </span>
                    ',
                    ];
                    break;
                case 'Rejected':
                    if ($isApprovedPhoto) {
                        $form['application_section']['esncard_photo']['status_badge'] = [
                            '#markup' => '
                        <span class="badge badge-warning">
                            <span class="material-symbols-outlined">warning</span> 
                            Pending Issues
                        </span>
                        ',
                        ];
                    } elseif ($isRejectionFixable ?? false) {
                        $form['application_section']['esncard_photo']['photo_upload'] = [
                            '#type' => 'managed_file',
                            '#title_display' => 'invisible',
                            '#upload_location' => 'membership://temp_uploads/',
                            '#upload_validators' => [
                                'file_validate_extensions' => ['jpg jpeg png'],
                                'file_validate_size' => [8 * 1024 * 1024]
                            ],
                            '#attributes' => [
                                'class' => ['auto-submit-file'],
                            ],
                            '#process' => [
                                ['\Drupal\file\Element\ManagedFile', 'processManagedFile'],
                                [$this, 'processPhotoUploadAttributes'],
                            ],
                        ];

                        $form['hidden_actions']['submit_photo_file'] = [
                            '#type' => 'submit',
                            '#value' => 'Save Photo File',
                            '#submit' => ['::submitPhotoFile'],
                            '#attributes' => [
                                'id' => 'edit-photo-upload-submit'
                            ],
                        ];
                    } else {
                        $form['application_section']['esncard_photo']['status_badge'] = [
                            '#markup' => '
                        <span class="badge badge-error">
                            <span class="material-symbols-outlined">cancel</span> 
                            Rejected
                        </span>
                        ',
                        ];
                    }
                    break;
            }

            $form['application_section']['esncard_photo']['content_bottom'] = [
                '#markup' => '</div></div>',
            ];
        }

        if ($isApproved && ($moduleSettings->getGoogleWalletSwitch() || $moduleSettings->getAppleWalletSwitch())) {
            $form['wallet_section'] = [
                '#type' => 'container',
                '#attributes' => [
                    'class' => ['section'],
                    'id' => 'wallet',
                ],
            ];

            $form['wallet_section']['title'] = [
                '#markup' => '<h2>Digital Wallets</h2>',
            ];

            $form['wallet_section']['add_to_wallet'] = [
                '#type' => 'container',
                '#attributes' => [
                    'class' => ['panel']
                ],
            ];

            if ($moduleSettings->getGoogleWalletSwitch()) {
                $googleWalletPassLink = Url::fromRoute(
                    'esn_membership_manager.add_to_google_wallet',
                    ['identifier' => $application->getValue(ApplicationField::PassToken)],
                    ['absolute' => TRUE]
                )->toString();
            }

            if ($moduleSettings->getAppleWalletSwitch()) {
                $appleWalletPassLink = Url::fromRoute(
                    'esn_membership_manager.download_apple_pass',
                    ['identifier' => $application->getValue(ApplicationField::PassToken)],
                    ['absolute' => TRUE]
                )->toString();
            }

            if (in_array($application->getApprovalStatus(), ['Paid', 'Issued', 'Delivered'])) {
                if ($moduleSettings->getGoogleWalletSwitch()) {
                    $googleWalletCardLink = Url::fromRoute(
                        'esn_membership_manager.add_to_google_wallet',
                        ['identifier' => $application->getValue(ApplicationField::ESNcardNumber)],
                        ['absolute' => TRUE]
                    )->toString();
                }

                if ($moduleSettings->getAppleWalletSwitch()) {
                    $appleWalletCardLink = Url::fromRoute(
                        'esn_membership_manager.download_apple_pass',
                        ['identifier' => $application->getValue(ApplicationField::ESNcardNumber)],
                        ['absolute' => TRUE]
                    )->toString();
                }
            }

            $form['wallet_section']['add_to_wallet']['content'] = [
                '#type' => 'inline_template',
                '#template' => '
                <div class="panel-header">
                    <h3>Add to Wallet</h3>
                </div>
                <div class="divided-panel-body">
                    <div>
                        <h4>{{ pass_name }}</h4>
                        {% if google_pass is not empty %}
                        <a href="{{ google_pass }}">
                            <img alt="Add to Google Wallet"
                                 src="https://esncy.org/sites/default/files/2025-12/add_to_google_wallet_badge.png"
                                 height="45">
                        </a>
                        {% endif %}
                        {% if apple_pass is not empty %}
                        <a href="{{ apple_pass }}">
                            <img alt="Add to Apple Wallet"
                                 src="https://esncy.org/sites/default/files/2026-02/Add_to_Apple_Wallet.png"
                                 height="45">
                        </a>
                        {% endif %}
                    </div>
                    {% if (google_card is not empty) or (apple_card is not empty) %}
                    <div class="panel-divider"></div>
                    <div>
                        <h4>ESNcard</h4>
                        {% if google_card is not empty %}
                        <a href="{{ google_card }}">
                            <img alt="Add to Google Wallet"
                                 src="https://esncy.org/sites/default/files/2025-12/add_to_google_wallet_badge.png"
                                 height="45">
                        </a>
                        {% endif %}
                        {% if apple_card is not empty %}
                        <a href="{{ apple_card }}">
                            <img alt="Add to Apple Wallet"
                                 src="https://esncy.org/sites/default/files/2026-02/Add_to_Apple_Wallet.png"
                                 height="45">
                        </a>
                        {% endif %}
                    </div>
                    {% endif %}
                </div>
            ',
                '#context' => [
                    'pass_name' => $moduleSettings->getPassName(),
                    'google_pass' => $googleWalletPassLink ?? null,
                    'apple_pass' => $appleWalletPassLink ?? null,
                    'google_card' => $googleWalletCardLink ?? null,
                    'apple_card' => $appleWalletCardLink ?? null,
                ],
            ];
        }

        return $form;
    }

    /**
     * @noinspection PhpUnusedParameterInspection
     */
    public function processFileUploadAttributes(&$element, FormStateInterface $form_state, &$complete_form): array
    {
        if (isset($element['upload'])) {
            $element['upload']['#attributes']['accept'] = 'image/jpeg, image/png, application/pdf';
        }

        return $element;
    }

    /**
     * @noinspection PhpUnusedParameterInspection
     */
    public function processPhotoUploadAttributes(&$element, FormStateInterface $form_state, &$complete_form): array
    {
        if (isset($element['upload'])) {
            $element['upload']['#attributes']['accept'] = 'image/jpeg, image/png';
        }

        return $element;
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    public function submitPersonalFields(array &$form, FormStateInterface $form_state): void
    {
        if (!$form_state->get('is_approved_identity')) return;

        $application = $this->loadUserApplication();
        if (!$application) return;

        $changesMade = false;

        if ($application->getValue(ApplicationField::Name) != ($newName = trim($form_state->getValue('name')))) {
            $application->setValue(ApplicationField::Name, $newName);
            $changesMade = true;
        }

        if ($application->getValue(ApplicationField::Surname) != ($newSurname = trim($form_state->getValue('surname')))) {
            $application->setValue(ApplicationField::Surname, $newSurname);
            $changesMade = true;
        }

        if ($application->getValue(ApplicationField::Nationality) != ($newNationality = trim($form_state->getValue('nationality')))) {
            $application->setValue(ApplicationField::Nationality, $newNationality);
            $changesMade = true;
        }

        if ($application->getDateOfBirth()->format('Y-m-d') != ($newDateOfBirth = trim($form_state->getValue('date_of_birth')))) {
            $application->setValue(ApplicationField::DateOfBirth, DrupalDateTime::createFromFormat('Y-m-d', $newDateOfBirth)->format('Y-m-d\TH:i:s'));
            $changesMade = true;
        }

        if ($changesMade) {
            $application->save();
            $this->messenger()->addStatus($this->t('Your personal information has been updated.'));
        }
    }

    /**
     * Helper method to load the current user's membership application.
     * * @return ApplicationInterface|null
     * The application entity, or null if not found/error.
     */
    protected function loadUserApplication(): ?ApplicationInterface
    {
        if (empty($this->authenticatedEmail)) {
            $this->messenger()->addError($this->t('Could not verify your session. Please log in again.'));
            return null;
        }

        try {
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager->getStorage('membership_application');
            $application = $storage->getByEmailAddress($this->authenticatedEmail);

            if (!$application) {
                $this->messenger()->addError($this->t('No active application found for this email.'));
                return null;
            }

            return $application;
        } catch (Exception $e) {
            $this->messenger()->addError($this->t('A system error occurred while loading your application.'));
            $this->logger->error('Dashboard application retrieval failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    public function submitMobilityFields(array &$form, FormStateInterface $form_state): void
    {
        if (!$form_state->get('is_approved_status')) return;

        $application = $this->loadUserApplication();
        if (!$application) return;

        $changesMade = false;

        if ($application->getValue(ApplicationField::MobilityStatus) != ($newMobilityStatus = trim($form_state->getValue('mobility_status')))) {
            $application->setValue(ApplicationField::MobilityStatus, $newMobilityStatus);
            $changesMade = true;
        }

        if ($application->getValue(ApplicationField::HostInstitution) != ($newHostInstitution = trim($form_state->getValue('host_institution')))) {
            $application->setValue(ApplicationField::HostInstitution, $newHostInstitution);
            $changesMade = true;
        }

        if ($changesMade) {
            $application->save();
            $this->messenger()->addStatus($this->t('Your mobility information has been updated.'));
        }
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    public function submitIdentityFile(array &$form, FormStateInterface $form_state): void
    {
        $application = $this->loadUserApplication();
        if (!$application) return;

        $fids = $form_state->getValue('identity_upload');

        if (!empty($fids) && isset($fids[0])) {
            $this->submitFile('Identity', $fids[0]);
        }
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    private function submitFile(string $fileType, string $fileID): void
    {
        $application = $this->loadUserApplication();
        if (!$application) return;

        $fileContents = $this->fileService->readFile($fileID);

        if (!empty($fileContents)) {
            if ($fileType == 'Identity') {
                if ($file = $application->getIDDocument()) {
                    if ($this->fileService->replaceFileData($file->id(), $fileContents)) {
                        $application->save();

                        $this->messenger()->addStatus($this->t('Identity document uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Identity document upload failed. Please try again later.'));
                        return;
                    }
                } else {
                    if ($fileID = $this->fileService->createApplicationFile($fileContents, 'membership://' . $application->id(), 'id_document', $application->id())) {
                        $application->setValue(ApplicationField::IdentityDocumentFileID, $fileID);
                        $application->save();

                        $this->messenger()->addStatus($this->t('Identity document uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Identity document upload failed. Please try again later.'));
                        return;
                    }
                }
            } elseif ($fileType == 'Status') {
                if ($file = $application->getStatusDocument()) {
                    if ($this->fileService->replaceFileData($file->id(), $fileContents)) {
                        $application->save();

                        $this->messenger()->addStatus($this->t('Proof of status uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Proof of status upload failed. Please try again later.'));
                        return;
                    }
                } else {
                    if ($fileID = $this->fileService->createApplicationFile($fileContents, 'membership://' . $application->id(), 'status', $application->id())) {
                        $application->setValue(ApplicationField::StatusProofFileID, $fileID);
                        $application->save();

                        $this->messenger()->addStatus($this->t('Proof of status uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Proof of status upload failed. Please try again later.'));
                        return;
                    }
                }
            } elseif ($fileType == 'Photo') {
                if ($file = $application->getFacePhoto()) {
                    if ($this->fileService->replaceFileData($file->id(), $fileContents)) {
                        $application->save();

                        $this->messenger()->addStatus($this->t('Face photo uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Face photo upload failed. Please try again later.'));
                        return;
                    }
                } else {
                    if ($fileID = $this->fileService->createApplicationFile($fileContents, 'membership://' . $application->id(), 'face_photo', $application->id())) {
                        $application->setValue(ApplicationField::FacePhotoFileID, $fileID);
                        $application->save();

                        $this->messenger()->addStatus($this->t('Face photo uploaded successfully. Your application will be reviewed soon.'));
                    } else {
                        $this->messenger()->addError($this->t('Face photo upload failed. Please try again later.'));
                        return;
                    }
                }
            }

            if ($application->getApprovalStatus() == 'Rejected') {
                $reasons = $application->getAllReasons();
                $reasonsString = '';

                foreach ($reasons as $reason) {
                    if ($reason['status'] == 'Rejected' && $reason['category'] == $fileType) {
                        $reason['status'] = 'Pending';
                    }

                    $reasonsString .= "{$reason['status']}-{$reason['category']}-{$reason['issue']}/";
                }

                $application->setValue(ApplicationField::ApprovalStatus, rtrim($reasonsString, "/"));
            } else {
                $application->setValue(ApplicationField::ApprovalStatus, 'Pending');
            }
            $application->save();
        }
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    public function submitStatusFile(array &$form, FormStateInterface $form_state): void
    {
        $application = $this->loadUserApplication();
        if (!$application) return;

        $fids = $form_state->getValue('status_upload');

        if (!empty($fids) && isset($fids[0])) {
            $this->submitFile('Status', $fids[0]);
        }
    }

    /**
     * @throws EntityStorageException
     * @noinspection PhpUnusedParameterInspection
     */
    public function submitPhotoFile(array &$form, FormStateInterface $form_state): void
    {
        $application = $this->loadUserApplication();
        if (!$application) return;

        $fids = $form_state->getValue('photo_upload');

        if (!empty($fids) && isset($fids[0])) {
            $this->submitFile('Photo', $fids[0]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
    }
}