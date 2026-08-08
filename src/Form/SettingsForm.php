<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Service\WeeztixService;
use Drupal\esn_membership_manager\Utility\MobilityStatuses;
use Drupal\omnia\Config\OmniaSettings;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Defines a configuration form for ESN Membership Manager settings.
 */
class SettingsForm extends ConfigFormBase
{
    protected WeeztixService $weeztixService;
    protected StateInterface $state;
    protected FileSystemInterface $fileSystem;
    protected ModuleHandlerInterface $moduleHandler;

    public function __construct(
        ConfigFactoryInterface $configFactory,
        WeeztixService         $weeztixService,
        StateInterface         $state,
        FileSystemInterface    $fileSystem,
        ModuleHandlerInterface $moduleHandler,
    )
    {
        parent::__construct($configFactory);
        $this->weeztixService = $weeztixService;
        $this->state = $state;
        $this->fileSystem = $fileSystem;
        $this->moduleHandler = $moduleHandler;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var WeeztixService $weeztixService */
        $weeztixService = $container->get('esn_membership_manager.weeztix_service');

        /** @var StateInterface $state */
        $state = $container->get('state');

        /** @var FileSystemInterface $fileSystem */
        $fileSystem = $container->get('file_system');

        /** @var ModuleHandlerInterface $moduleHandler */
        $moduleHandler = $container->get('module_handler');

        return new static(
            $configFactory,
            $weeztixService,
            $state,
            $fileSystem,
            $moduleHandler,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_settings_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $membershipSettings = new MembershipSettings($this->configFactory());

        $form['switches'] = [
            '#type' => 'details',
            '#title' => $this->t('Enable / Disable Features'),
            '#open' => true
        ];

        $form['switches']['switch_guest_pass'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Guest Passes'),
            '#default_value' => $membershipSettings->getGuestPassSwitch(),
        ];

        if ($this->moduleHandler->moduleExists('estia_housing')) {
            $form['switches']['switch_estia'] = [
                '#type' => 'checkbox',
                '#title' => $this->t('Enable Estia Housing Integration'),
                '#default_value' => $membershipSettings->getEstiaSwitch(),
            ];
        }

        $form['switches']['switch_weeztix'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Weeztix Integration'),
            '#default_value' => $membershipSettings->getWeeztixSwitch(),
        ];

        $form['switches']['switch_google_sheets'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Google Sheets Integration'),
            '#default_value' => $membershipSettings->getGoogleSheetsSwitch(),
        ];

        $form['switches']['switch_google_wallet'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Google Wallet Integration'),
            '#default_value' => $membershipSettings->getGoogleWalletSwitch(),
        ];

        $form['switches']['switch_apple_wallet'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Apple Wallet Integration'),
            '#default_value' => $membershipSettings->getAppleWalletSwitch(),
        ];

        $form['switches']['switch_didit'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Didit Integration'),
            '#default_value' => $membershipSettings->getDiditSwitch(),
        ];

        $form['general'] = [
            '#type' => 'details',
            '#title' => $this->t('General Settings'),
            '#description' => $this->t('Configuration for the ESN Membership Manager module.'),
            '#open' => true
        ];

        $form['general']['pass_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Pass Scheme Name'),
            '#description' => $this->t('Enter the name of the Pass Scheme.'),
            '#default_value' => $membershipSettings->getPassName(),
            '#required' => true
        ];

        $form['email'] = [
            '#type' => 'details',
            '#title' => $this->t('Email Settings'),
            '#description' => $this->t('Configuration for the parameters needed for sending emails.'),
            '#open' => true
        ];

        $form['email']['email_address'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Address'),
            '#description' => $this->t('Enter the email address from where the emails will be sent.'),
            '#default_value' => $membershipSettings->getEmailAddress(),
            '#required' => true
        ];

        $form['email']['email_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Name'),
            '#description' => $this->t('Enter the user-friendly name from where the emails will be sent.'),
            '#default_value' => $membershipSettings->getEmailName(),
            '#required' => true
        ];

        $form['email']['email_footer'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Email Footer'),
            '#description' => $this->t('Enter the HTML for the footer of the emails to be sent.'),
            '#default_value' => $membershipSettings->getEmailFooter(),
            '#required' => true
        ];

        $form['email']['email_admin_address'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Administrator Email Address'),
            '#description' => $this->t('Enter the email address of the administrator of the platform.'),
            '#default_value' => $membershipSettings->getAdminEmailAddress(),
            '#required' => true
        ];

        $guestPassEnabled = $form_state->getValue('switch_guest_pass') ?? $membershipSettings->getGuestPassSwitch();


        $form['guest_pass'] = [
            '#type' => 'details',
            '#title' => $this->t('Guest Pass Settings'),
            '#description' => $this->t('Configuration for the parameters needed for Guest Passes.'),
            '#open' => $guestPassEnabled
        ];

        $form['guest_pass']['guest_pass_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Guest Pass Scheme Name'),
            '#description' => $this->t('Enter the name of the Guest Pass Scheme.'),
            '#default_value' => $membershipSettings->getGuestPassName(),
            '#required' => $guestPassEnabled
        ];

        $form['guest_pass']['guest_pass_instant_limit'] = [
            '#type' => 'number',
            '#title' => $this->t('Instant Limit'),
            '#description' => $this->t('Enter the Instant Guest Pass Limit. After a single application requests more than the instant limit, their guest pass applications will be waitlisted.'),
            '#default_value' => $membershipSettings->getGuestPassInstantLimit(),
            '#required' => $guestPassEnabled
        ];

        $form['guest_pass']['guest_pass_special_mobilities'] = [
            '#type' => 'select',
            '#title' => $this->t('Special Mobility Statuses'),
            '#description' => $this->t('Select the mobilities that get special rules for guest passes. The special rules override the instant limit and apply quantitative limits per person and per event.'),
            '#options' => MobilityStatuses::getGroupedOptions(),
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => $membershipSettings->getGuestPassSpecialMobilities(),
            '#multiple' => true,
        ];

        $form['guest_pass']['special_interval'] = [
            '#type' => 'fieldset',
            '#title' => $this->t('Special Interval'),
            '#description' => $this->t('Define the interval for the special rules.'),
        ];

        $form['guest_pass']['special_interval']['container'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['container-inline']],
        ];

        $form['guest_pass']['special_interval']['container']['guest_pass_special_interval_number'] = [
            '#type' => 'number',
            '#default_value' => !empty($membershipSettings->getGuestPassSpecialInterval()) ? substr($membershipSettings->getGuestPassSpecialInterval(), 1, -1) : null,
        ];

        $form['guest_pass']['special_interval']['container']['guest_pass_special_interval_period'] = [
            '#type' => 'select',
            '#options' => [
                'D' => $this->t('Day(s)'),
                'M' => $this->t('Month(s)'),
                'Y' => $this->t('Year(s)'),
            ],
            '#empty_option' => $this->t('- Select -'),
            '#default_value' => !empty($membershipSettings->getGuestPassSpecialInterval()) ? substr($membershipSettings->getGuestPassSpecialInterval(), -1, 1) : null,
        ];

        $form['guest_pass']['guest_pass_special_per_person_limit'] = [
            '#type' => 'number',
            '#title' => $this->t('Special Per Person Limit'),
            '#description' => $this->t('Enter the Special Per Person Limit. After a special application requests more than this limit per interval, their guest pass applications will be waitlisted.'),
            '#default_value' => $membershipSettings->getGuestPassSpecialPerPersonLimit(),
        ];

        $form['guest_pass']['guest_pass_special_concurrent_limit'] = [
            '#type' => 'number',
            '#title' => $this->t('Special Concurrent Limit'),
            '#description' => $this->t('Enter the Special Concurrent Limit. After a special application requests a guest pass after the concurrent limit per interval, their guest pass applications will be waitlisted.'),
            '#default_value' => $membershipSettings->getGuestPassSpecialConcurrentLimit(),
        ];

        $form['stripe'] = [
            '#type' => 'details',
            '#title' => $this->t('Stripe Settings'),
            '#description' => $this->t('Configuration for the Stripe parameters needed for payment processing.'),
            '#open' => true
        ];

        $form['stripe']['stripe_webhook_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Webhook Secret'),
            '#description' => $this->t('Enter the Stripe Webhook Secret.'),
            '#default_value' => $membershipSettings->getStripeWebhookSecret(),
            '#required' => true
        ];

        $form['stripe']['stripe_price_esncard'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNcard'),
            '#description' => $this->t('Enter the Stripe Price ID for the main ESNcard product.'),
            '#default_value' => $membershipSettings->getESNcardPriceID(false),
            '#required' => true
        ];

        $form['stripe']['stripe_price_processing'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for Processing Fee'),
            '#description' => $this->t('Enter the Stripe Price ID for the processing fee product.'),
            '#default_value' => $membershipSettings->getProcessingPriceID(false),
            '#required' => false
        ];

        $form['stripe']['stripe_price_esncard_esner'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNer ESNcard'),
            '#description' => $this->t('Enter the Stripe Price ID for the ESNer ESNcard product.'),
            '#default_value' => $membershipSettings->getESNcardPriceID(true),
            '#required' => true
        ];

        $form['stripe']['stripe_price_processing_esner'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNer Processing Fee'),
            '#description' => $this->t('Enter the Stripe Price ID for the ESNer processing fee product.'),
            '#default_value' => $membershipSettings->getProcessingPriceID(true),
            '#required' => false
        ];

        $weeztixEnabled = $form_state->getValue('switch_weeztix') ?? $membershipSettings->getWeeztixSwitch();

        $form['weeztix'] = [
            '#type' => 'details',
            '#title' => $this->t('Weeztix Settings'),
            '#description' => $this->t('Configuration for the Weeztix Service.'),
            '#open' => $weeztixEnabled
        ];

        $accessToken = $this->state->get('esn_membership_manager.weeztix_access_token');

        if ($accessToken) {
            $form['weeztix']['weeztix_status_message'] = [
                '#type' => 'markup',
                '#markup' => '<div class="alert alert-success">' . $this->t('Connected to Weeztix API.') . '</div>',
            ];
        } else {
            $form['weeztix']['weeztix_status_message'] = [
                '#type' => 'markup',
                '#markup' => '<div class="alert alert-warning">' . $this->t('Not connected to Weeztix. Please save credentials and click "Authorize" below.') . '</div>',
            ];
        }

        $form['weeztix']['weeztix_client_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client ID'),
            '#default_value' => $membershipSettings->getWeeztixClientID(),
            '#disabled' => !$weeztixEnabled,
            '#required' => $weeztixEnabled
        ];

        $form['weeztix']['weeztix_client_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client Secret'),
            '#default_value' => $membershipSettings->getWeeztixClientSecret(),
            '#disabled' => !$weeztixEnabled,
            '#required' => $weeztixEnabled
        ];

        $form['weeztix']['weeztix_pass_coupon_list_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Pass Coupon List ID / Campaign ID'),
            '#description' => $this->t('The ID of the list where the pass coupons should be added.'),
            '#default_value' => $membershipSettings->getWeeztixPassCouponListID(),
            '#disabled' => !$weeztixEnabled
        ];

        $form['weeztix']['weeztix_card_coupon_list_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('ESNcard Coupon List ID / Campaign ID'),
            '#description' => $this->t('The ID of the list where the ESNcard coupons should be added.'),
            '#default_value' => $membershipSettings->getWeeztixCardCouponListID(),
            '#disabled' => !$weeztixEnabled,
            '#required' => $weeztixEnabled
        ];

        if ($weeztixEnabled) {
            $redirectURI = Url::fromRoute('esn_membership_manager.weeztix_oauth_callback', [], ['absolute' => TRUE])->toString();

            $state = Crypt::randomBytesBase64(64);
            $session = $this->getRequest()->getSession();
            $session->set('weeztix_oauth_state', $state);

            $authURL = $this->weeztixService->getAuthorizationUrl($redirectURI, $state);

            if ($authURL) {
                $form['weeztix']['auth_link'] = [
                    '#type' => 'link',
                    '#title' => $this->t('Authorize with Weeztix'),
                    '#url' => Url::fromUri($authURL),
                    '#attributes' => [
                        'class' => ['button', 'button--primary'],
                        'style' => 'margin-top: 1em;',
                    ],
                    '#suffix' => '<p class="description">' . $this->t('Note: Ensure <strong>@url</strong> is added as a Redirect URI in your Weeztix Dashboard.', ['@url' => $redirectURI]) . '</p>',
                ];
            }
        }

        $googleSheetsEnabled = $form_state->getValue('switch_google_sheets') ?? $membershipSettings->getGoogleSheetsSwitch();

        $form['google'] = [
            '#type' => 'details',
            '#title' => $this->t('Google Settings'),
            '#description' => $this->t('Configuration for the Google Service.'),
            '#open' => $googleSheetsEnabled
        ];

        $form['google']['google_spreadsheet_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Spreadsheet ID'),
            '#description' => $this->t('The long ID string from the Google Sheet URL.'),
            '#default_value' => $membershipSettings->getSpreadsheetID(),
            '#disabled' => !$googleSheetsEnabled,
            '#required' => $googleSheetsEnabled
        ];

        $form['google']['google_sheet_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sheet Name'),
            '#description' => $this->t('The name of the specific tab (e.g., "Data").'),
            '#default_value' => $membershipSettings->getSheetName(),
            '#disabled' => !$googleSheetsEnabled,
            '#required' => $googleSheetsEnabled
        ];

        $appleWalletEnabled = $form_state->getValue('switch_apple_wallet') ?? $membershipSettings->getAppleWalletSwitch();

        $form['apple'] = [
            '#type' => 'details',
            '#title' => $this->t('Apple Wallet Settings'),
            '#description' => $this->t('Configuration for the Apple Wallet Service.'),
            '#open' => $appleWalletEnabled
        ];

        $certString = $membershipSettings->getAppleCertificateP12();

        if ($certString) {
            $form['apple']['current_status'] = [
                '#markup' => '<div class="alert alert-success">' .
                    $this->t('Certificate already uploaded.') .
                    '</div>',
            ];
        } else {
            $form['apple']['current_status'] = [
                '#markup' => '<div class="alert alert-warning>' .
                    $this->t('No Certificate has been uploaded.') .
                    '</div>',
            ];
        }

        $form['apple']['apple_certificate_file'] = [
            '#type' => 'file',
            '#title' => $this->t('Upload Pass Certificate'),
            '#description' => $this->t('Upload the .p12 file you created and contains the Pass Signing Certificate.'),
            '#attributes' => [
                'accept' => '.p12',
            ],
            '#disabled' => !$appleWalletEnabled,
            '#required' => empty($certString) && $appleWalletEnabled
        ];

        $form['apple']['apple_certificate_password'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Pass Certificate Password'),
            '#description' => $this->t('The password you have set for your .p12 file.'),
            '#default_value' => $membershipSettings->getAppleCertificatePassword(),
            '#disabled' => !$appleWalletEnabled,
            '#required' => $appleWalletEnabled
        ];

        $form['apple']['apple_pass_type_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Pass Type ID'),
            '#description' => $this->t('The Pass Type ID created on Certificates, Identifiers & Profiles.'),
            '#default_value' => $membershipSettings->getApplePassTypeID(),
            '#disabled' => !$appleWalletEnabled,
            '#required' => $appleWalletEnabled
        ];

        $diditEnabled = $form_state->getValue('switch_didit') ?? $membershipSettings->getDiditSwitch();

        $form['didit'] = [
            '#type' => 'details',
            '#title' => $this->t('Didit Settings'),
            '#description' => $this->t('Configuration for the Didit Service.'),
            '#open' => $diditEnabled
        ];

        $form['didit']['didit_api_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Application API Key'),
            '#description' => $this->t('The API key found in Developers > API Keys.'),
            '#default_value' => $membershipSettings->getDiditAPIKey(),
            '#disabled' => !$diditEnabled,
            '#required' => $diditEnabled
        ];

        $form['didit']['didit_workflow_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Workflow ID'),
            '#description' => $this->t('The ID of the workflow to be used for verification.'),
            '#default_value' => $membershipSettings->getDiditWorkflowID(),
            '#disabled' => !$diditEnabled,
            '#required' => $diditEnabled
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);

        $allFiles = $this->getRequest()->files->get('files', []);

        $appleFile = $allFiles['apple_certificate_file'] ?? NULL;
        if ($appleFile instanceof UploadedFile) {
            if ($appleFile->isValid()) {
                $content = file_get_contents($appleFile->getRealPath());
                $form_state->set('apple_certificate_string_p12', $content);

                $password = $form_state->getValue('apple_certificate_password');
                try {
                    $pemCertificate = $this->readP12($content, $password);
                    $form_state->set('apple_certificate_string_pem', $pemCertificate);
                } catch (Exception $e) {
                    $this->messenger()->addError($this->t($e->getMessage()));
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $membershipSettings = new MembershipSettings($this->configFactory(), true);

        $membershipSettings
            ->setGuestPassSwitch($form_state->getValue('switch_guest_pass'))
            ->setEstiaSwitch($this->moduleHandler->moduleExists('estia_housing') && $form_state->getValue('switch_estia'))
            ->setWeeztixSwitch($form_state->getValue('switch_weeztix'))
            ->setGoogleSheetsSwitch($form_state->getValue('switch_google_sheets'))
            ->setGoogleWalletSwitch($form_state->getValue('switch_google_wallet'))
            ->setAppleWalletSwitch($form_state->getValue('switch_apple_wallet'))
            ->setDiditSwitch($form_state->getValue('switch_didit'))
            ->setPassName($form_state->getValue('pass_name'))
            ->setEmailAddress($form_state->getValue('email_address'))
            ->setEmailName($form_state->getValue('email_name'))
            ->setEmailFooter($form_state->getValue('email_footer'))
            ->setAdminEmailAddress($form_state->getValue('email_admin_address'))
            ->setGuestPassName($form_state->getValue('guest_pass_name'))
            ->setGuestPassInstantLimit($form_state->getValue('guest_pass_instant_limit'))
            ->setGuestPassSpecialPerPersonLimit($form_state->getValue('guest_pass_special_per_person_limit'))
            ->setGuestPassSpecialConcurrentLimit($form_state->getValue('guest_pass_special_concurrent_limit'))
            ->setStripeWebhookSecret($form_state->getValue('stripe_webhook_secret'))
            ->setESNcardPriceID($form_state->getValue('stripe_price_esncard'), false)
            ->setProcessingPriceID($form_state->getValue('stripe_price_processing'), false)
            ->setESNcardPriceID($form_state->getValue('stripe_price_esncard_esner'), true)
            ->setProcessingPriceID($form_state->getValue('stripe_price_processing_esner'), true)
            ->setWeeztixClientID($form_state->getValue('weeztix_client_id'))
            ->setWeeztixClientSecret($form_state->getValue('weeztix_client_secret'))
            ->setWeeztixPassCouponListID($form_state->getValue('weeztix_pass_coupon_list_id'))
            ->setWeeztixCardCouponListID($form_state->getValue('weeztix_card_coupon_list_id'))
            ->setSpreadsheetID($form_state->getValue('google_spreadsheet_id'))
            ->setSheetName($form_state->getValue('google_sheet_name'))
            ->setAppleCertificatePassword($form_state->getValue('apple_certificate_password'))
            ->setApplePassTypeID($form_state->getValue('apple_pass_type_id'))
            ->setDiditAPIKey($form_state->getValue('didit_api_key'))
            ->setDiditWorkflowID($form_state->getValue('didit_workflow_id'));

        if (!empty($form_state->getValue('guest_pass_special_mobilities'))) {
            if (is_array($form_state->getValue('guest_pass_special_mobilities'))) {
                $membershipSettings->setGuestPassSpecialMobilities($form_state->getValue('guest_pass_special_mobilities'));
            } else {
                $membershipSettings->setGuestPassSpecialMobilities([$form_state->getValue('guest_pass_special_mobilities')]);
            }
        }

        if (!empty($form_state->getValue('guest_pass_special_interval_number')) && !empty($form_state->getValue('guest_pass_special_interval_period'))) {
            $membershipSettings->setGuestPassSpecialInterval('P' . $form_state->getValue('guest_pass_special_interval_number') . $form_state->getValue('guest_pass_special_interval_period'));
        }

        $appleCertificateP12 = $form_state->get('apple_certificate_string_p12');
        $appleCertificatePEM = $form_state->get('apple_certificate_string_pem');
        if ($appleCertificateP12 && $appleCertificatePEM) {
            $membershipSettings->setAppleCertificateP12($appleCertificateP12);
            $membershipSettings->setAppleCertificatePEM($appleCertificatePEM);
            $this->messenger()->addStatus($this->t('The Apple Pass Certificate has been saved.'));
        }

        $membershipSettings->save();

        parent::submitForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return [MembershipSettings::CONFIG_NAME];
    }

    public function switchToggle(array $form, FormStateInterface $form_state): array
    {
        $omniaSettings = new OmniaSettings($this->configFactory());
        $omniaSettingsURL = Url::fromRoute('omnia.settings', [], ['absolute' => true])->toString();

        if ($form_state->getValue('switch_google_sheets') || $form_state->getValue('switch_google_wallet')) {
            if ($omniaSettings->getGoogleSwitch()) {
                if (empty($omniaSettings->getGoogleClientEmail())) {
                    $form_state->setValue('switch_google_sheets', false);
                    $form_state->setValue('switch_google_wallet', false);
                    $this->messenger()->addError(Markup::create('<p>Please configure the Google Credentials in the <a href="' . $omniaSettingsURL . '">Omnia Settings</a> before you enable the integration here.</p>'));
                }

                if ($form_state->getValue('switch_google_wallet') && empty($omniaSettings->getGoogleIssuerID())) {
                    $form_state->setValue('switch_google_wallet', false);
                    $this->messenger()->addError(Markup::create('<p>Please configure the Google Wallet Issuer ID in the <a href="' . $omniaSettingsURL . '">Omnia Settings</a> before you enable the integration here.</p>'));
                }
            } else {
                $form_state->setValue('switch_google_sheets', false);
                $form_state->setValue('switch_google_wallet', false);
                $this->messenger()->addError(Markup::create('<p>Please enable the Google integration in the <a href="' . $omniaSettingsURL . '">Omnia Settings</a> before you enable the integration here.</p>'));
            }
        }

        if ($form_state->getValue('switch_apple_wallet')) {
            if ($omniaSettings->getAppleSwitch()) {
                if (empty($omniaSettings->getAppleTeamID())) {
                    $form_state->setValue('switch_apple_wallet', false);
                    $this->messenger()->addError(Markup::create('<p>Please configure the Apple Team ID in the <a href="' . $omniaSettingsURL . '">Omnia Settings</a> before you enable the integration here.</p>'));
                }
            } else {
                $form_state->setValue('switch_apple_wallet', false);
                $this->messenger()->addError(Markup::create('<p>Please enable the Apple integration in the <a href="' . $omniaSettingsURL . '">Omnia Settings</a> before you enable the integration here.</p>'));
            }
        }

        return $form;
    }

    /**
     * @throws Exception
     */
    private function readP12(string $p12String, string $password): string
    {
        $certs = [];
        if (openssl_pkcs12_read($p12String, $certs, $password)) {
            return $certs['cert'] . "\n" . $certs['pkey'];
        }

        $error = '';
        while ($text = openssl_error_string()) {
            $error .= $text;
        }

        if (!str_contains($error, 'digital envelope routines::unsupported')) {
            throw new Exception('Invalid certificate file or password. OpenSSL Error: ' . $error);
        }

        $tempDir = $this->fileSystem->getTempDirectory();
        $certificatePath = $this->fileSystem->tempnam($tempDir, 'apple_cert_') . '.p12';
        if (empty($certificatePath)) {
            throw new Exception('Could not create temporary certificate file.');
        }

        if (!file_put_contents($certificatePath, $p12String)) {
            throw new Exception('Could not write to the temporary certificate file.');
        }

        $value = shell_exec(
            "openssl pkcs12 -in " . escapeshellarg($certificatePath) .
            " -passin " . escapeshellarg("pass:" . $password) .
            " -passout " . escapeshellarg("pass:" . $password) .
            " -legacy"
        );

        unlink($certificatePath);

        if (empty($value)) {
            throw new Exception('Could not read certificate file.');
        }

        return $value;
    }
}