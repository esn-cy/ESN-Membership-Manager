<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Service\WeeztixApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Defines a configuration form for ESN Membership Manager settings.
 */
class SettingsForm extends ConfigFormBase
{
    protected WeeztixApiService $apiService;
    protected StateInterface $state;
    protected $requestStack;

    public function __construct(
        ConfigFactoryInterface $config_factory,
        WeeztixApiService      $api_service,
        StateInterface         $state,
        RequestStack           $request_stack
    )
    {
        parent::__construct($config_factory);
        $this->apiService = $api_service;
        $this->state = $state;
        $this->requestStack = $request_stack;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var WeeztixApiService $api_service */
        $api_service = $container->get('esn_membership_manager.weeztix_api_service');

        /** @var StateInterface $state */
        $state = $container->get('state');

        /** @var RequestStack $request_stack */
        $request_stack = $container->get('request_stack');

        return new static(
            $configFactory,
            $api_service,
            $state,
            $request_stack
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
        $config = $this->config('esn_membership_manager.settings');

        $form['switches'] = [
            '#type' => 'details',
            '#title' => $this->t('Enable / Disable Features'),
            '#open' => TRUE
        ];

        $form['switches']['switch_weeztix'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Weeztix Integration'),
            '#default_value' => $config->get('switch_weeztix') ?? FALSE,
        ];

        $form['switches']['switch_google_sheets'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Google Sheets Integration'),
            '#default_value' => $config->get('switch_google_sheets') ?? FALSE,
        ];

        $form['switches']['switch_google_wallet'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Enable Google Wallet Integration'),
            '#default_value' => $config->get('switch_google_wallet') ?? FALSE,
        ];

        $form['general'] = [
            '#type' => 'details',
            '#title' => $this->t('General Settings'),
            '#description' => $this->t('Configuration for the ESN Membership Manager module.'),
            '#open' => TRUE
        ];

        $form['general']['scheme_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Free Pass Scheme Name'),
            '#description' => $this->t('Enter the Webform ID where the applications are made.'),
            '#default_value' => $config->get('scheme_name') ?? 'ESN Pass',
            '#required' => TRUE
        ];

        $form['general']['logo_url'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Logo URL'),
            '#description' => $this->t('Enter the url of the section logo.'),
            '#default_value' => $config->get('logo_url') ?? 'https://esn.org/sites/default/files/ESN_full-logo-Satellite.png',
            '#required' => TRUE
        ];

        $form['email'] = [
            '#type' => 'details',
            '#title' => $this->t('Email Settings'),
            '#description' => $this->t('Configuration for the parameters needed for sending emails.'),
            '#open' => TRUE
        ];

        $form['email']['email_from_address'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Address'),
            '#description' => $this->t('Enter the email address from where the emails will be sent.'),
            '#default_value' => $config->get('email_from_address'),
            '#required' => TRUE
        ];

        $form['email']['email_from_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sender Email Name'),
            '#description' => $this->t('Enter the user-friendly name from where the emails will be sent.'),
            '#default_value' => $config->get('email_from_name'),
            '#required' => TRUE
        ];

        $form['email']['email_footer'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Email Footer'),
            '#description' => $this->t('Enter the HTML for the footer of the emails to be sent.'),
            '#default_value' => $config->get('email_footer'),
            '#required' => FALSE
        ];

        $form['stripe'] = [
            '#type' => 'details',
            '#title' => $this->t('Stripe Settings'),
            '#description' => $this->t('Configuration for the Stripe parameters needed for payment processing.'),
            '#open' => TRUE
        ];

        $form['stripe']['stripe_secret_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Secret Key'),
            '#description' => $this->t('Enter the Stripe Secret Key.'),
            '#default_value' => $config->get('stripe_secret_key'),
            '#required' => TRUE
        ];

        $form['stripe']['stripe_webhook_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Webhook Secret'),
            '#description' => $this->t('Enter the Stripe Webhook Secret.'),
            '#default_value' => $config->get('stripe_webhook_secret'),
            '#required' => TRUE
        ];

        $form['stripe']['stripe_price_id_esncard'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNcard'),
            '#description' => $this->t('Enter the Stripe Price ID for the main ESNcard product.'),
            '#default_value' => $config->get('stripe_price_id_esncard'),
            '#required' => TRUE
        ];

        $form['stripe']['stripe_price_id_processing'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for Processing Fee'),
            '#description' => $this->t('Enter the Stripe Price ID for the processing fee product.'),
            '#default_value' => $config->get('stripe_price_id_processing'),
            '#required' => TRUE
        ];

        $form['weeztix'] = [
            '#type' => 'details',
            '#title' => $this->t('Weeztix Settings'),
            '#description' => $this->t('Configuration for the Weeztix Service.'),
            '#open' => $config->get('switch_weeztix') ?? FALSE
        ];

        $access_token = $this->state->get('esn_membership_manager.weeztix_access_token');

        if ($access_token) {
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
            '#default_value' => $config->get('weeztix_client_id'),
            '#disabled' => !$config->get('switch_weeztix') ?? TRUE,
            '#required' => $config->get('switch_weeztix') ?? FALSE
        ];

        $form['weeztix']['weeztix_client_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client Secret'),
            '#default_value' => $config->get('weeztix_client_secret'),
            '#disabled' => !$config->get('switch_weeztix') ?? TRUE,
            '#required' => $config->get('switch_weeztix') ?? FALSE
        ];

        $form['weeztix']['weeztix_coupon_list_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Coupon List ID / Campaign ID'),
            '#description' => $this->t('The ID of the list where coupons should be added.'),
            '#default_value' => $config->get('weeztix_coupon_list_id'),
            '#disabled' => !$config->get('switch_weeztix') ?? TRUE,
            '#required' => $config->get('switch_weeztix') ?? FALSE
        ];

        if ($config->get('switch_weeztix') ?? FALSE) {
            $redirect_uri = Url::fromRoute('esn_membership_manager.weeztix_oauth_callback', [], ['absolute' => TRUE])->toString();

            $state = Crypt::randomBytesBase64(64);
            $session = $this->requestStack->getCurrentRequest()->getSession();
            $session->set('weeztix_oauth_state', $state);

            $auth_url = $this->apiService->getAuthorizationUrl($redirect_uri, $state);

            if ($auth_url) {
                $form['weeztix']['auth_link'] = [
                    '#type' => 'link',
                    '#title' => $this->t('Authorize with Weeztix'),
                    '#url' => Url::fromUri($auth_url),
                    '#attributes' => [
                        'class' => ['button', 'button--primary'],
                        'style' => 'margin-top: 1em;',
                    ],
                    '#suffix' => '<p class="description">' . $this->t('Note: Ensure <strong>@url</strong> is added as a Redirect URI in your Weeztix Dashboard.', ['@url' => $redirect_uri]) . '</p>',
                ];
            }
        }

        $form['google'] = [
            '#type' => 'details',
            '#title' => $this->t('Google Settings'),
            '#description' => $this->t('Configuration for the Google Service.'),
            '#open' => ($config->get('switch_google_sheets') ?? FALSE) || ($config->get('switch_google_wallet') ?? FALSE)
        ];

        $email = $config->get('google_client_email');

        if ($email) {
            $form['google']['current_status'] = [
                '#markup' => '<div class="alert alert-success">' .
                    $this->t('Currently connected as: <strong>@email</strong>', ['@email' => $email]) .
                    '</div>',
            ];
        } else {
            $form['google']['current_status'] = [
                '#markup' => '<div class="alert alert-warning>' .
                    $this->t('No Service Account credentials configured.') .
                    '</div>',
            ];
        }

        $form['google']['google_json_key_file'] = [
            '#type' => 'file',
            '#title' => $this->t('Upload Google Service Account JSON'),
            '#description' => $this->t('Upload the .json file you downloaded from Google Console. The system will extract the keys and discard the file.'),
            '#attributes' => [
                'accept' => '.json',
            ],
            '#disabled' => (!$config->get('switch_google_sheets') ?? TRUE) && (!$config->get('switch_google_wallet') ?? TRUE),
            '#required' => empty($email) && (($config->get('switch_google_sheets') ?? FALSE) || ($config->get('switch_google_wallet') ?? FALSE))
        ];

        $form['google']['google_spreadsheet_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Spreadsheet ID'),
            '#description' => $this->t('The long ID string from the Google Sheet URL.'),
            '#default_value' => $config->get('google_spreadsheet_id'),
            '#disabled' => !$config->get('switch_google_sheets') ?? TRUE,
            '#required' => $config->get('switch_google_sheets') ?? FALSE
        ];

        $form['google']['google_sheet_name'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Sheet Name'),
            '#description' => $this->t('The name of the specific tab (e.g., "Data").'),
            '#default_value' => $config->get('google_sheet_name') ?? 'Data',
            '#disabled' => !$config->get('switch_google_sheets') ?? TRUE,
            '#required' => $config->get('switch_google_sheets') ?? FALSE
        ];

        $form['google']['google_issuer_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Issuer ID'),
            '#description' => $this->t('The Issuer ID from the Google Wallet Console.'),
            '#default_value' => $config->get('google_issuer_id'),
            '#disabled' => !$config->get('switch_google_wallet') ?? TRUE,
            '#required' => $config->get('switch_google_wallet') ?? FALSE
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state): void
    {
        parent::validateForm($form, $form_state);

        $all_files = $this->getRequest()->files->get('files', []);
        /** @var UploadedFile $file */
        $file = $all_files['google_json_key_file'] ?? NULL;

        if ($file instanceof UploadedFile) {
            if ($file->isValid()) {
                $content = file_get_contents($file->getRealPath());
                $json = json_decode($content, TRUE);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $form_state->setErrorByName('google_json_key_file', $this->t('The uploaded file is not valid JSON.'));
                    return;
                }

                if (empty($json['client_email']) || empty($json['private_key'])) {
                    $form_state->setErrorByName('google_json_key_file', $this->t('The JSON file does not contain "client_email" or "private_key". Are you sure this is a Service Account key file?'));
                    return;
                }

                $form_state->set('parsed_google_credentials', $json);
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $config = $this->config('esn_membership_manager.settings');

        $config
            ->set('switch_weeztix', $form_state->getValue('switch_weeztix'))
            ->set('switch_google_sheets', $form_state->getValue('switch_google_sheets'))
            ->set('switch_google_wallet', $form_state->getValue('switch_google_wallet'))
            ->set('logo_url', $form_state->getValue('logo_url'))
            ->set('email_from_address', $form_state->getValue('email_from_address'))
            ->set('email_from_name', $form_state->getValue('email_from_name'))
            ->set('email_footer', $form_state->getValue('email_footer'))
            ->set('stripe_secret_key', $form_state->getValue('stripe_secret_key'))
            ->set('stripe_webhook_secret', $form_state->getValue('stripe_webhook_secret'))
            ->set('stripe_price_id_esncard', $form_state->getValue('stripe_price_id_esncard'))
            ->set('stripe_price_id_processing', $form_state->getValue('stripe_price_id_processing'))
            ->set('weeztix_client_id', $form_state->getValue('weeztix_client_id'))
            ->set('weeztix_client_secret', $form_state->getValue('weeztix_client_secret'))
            ->set('weeztix_coupon_list_id', $form_state->getValue('weeztix_coupon_list_id'))
            ->set('google_spreadsheet_id', $form_state->getValue('google_spreadsheet_id'))
            ->set('google_sheet_name', $form_state->getValue('google_sheet_name'))
            ->set('google_issuer_id', $form_state->getValue('google_issuer_id'));

        $google_credentials = $form_state->get('parsed_google_credentials');

        if ($google_credentials) {
            $config->set('google_client_email', $google_credentials['client_email']);
            $config->set('google_private_key', $google_credentials['private_key']);
            $config->set('google_project_id', $google_credentials['project_id'] ?? '');
            $config->set('google_private_key_id', $google_credentials['private_key_id'] ?? '');
            $config->set('google_client_id', $google_credentials['client_id'] ?? '');

            $this->messenger()->addStatus($this->t('Credentials updated for @email. Remember to share your sheet with this email!', ['@email' => $google_credentials['client_email']]));
        }

        $config->save();

        parent::submitForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['esn_membership_manager.settings'];
    }
}