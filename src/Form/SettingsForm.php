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
        $access_token = $this->state->get('esn_membership_manager.weeztix_access_token');

        if ($access_token) {
            $form['weeztix_status_message'] = [
                '#type' => 'markup',
                '#markup' => '<div class="messages messages--status">' . $this->t('Connected to Weeztix API.') . '</div>',
            ];
        } else {
            $form['weeztix_status_message'] = [
                '#type' => 'markup',
                '#markup' => '<div class="messages messages--warning">' . $this->t('Not connected to Weeztix. Please save credentials and click "Authorize" below.') . '</div>',
            ];
        }

        $form['general'] = [
            '#type' => 'details',
            '#title' => $this->t('General Settings'),
            '#open' => TRUE,
            '#description' => $this->t('Configuration for the ESN Membership Manager module.'),
        ];

        $form['general']['webform_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Webform ID'),
            '#description' => $this->t('Enter the Webform ID where the applications are made.'),
            '#default_value' => $config->get('webform_id'),
            '#required' => TRUE,
        ];

        $form['stripe'] = [
            '#type' => 'details',
            '#title' => $this->t('Stripe Settings'),
            '#open' => TRUE,
            '#description' => $this->t('Configuration for the Stripe parameters needed for payment processing.'),
        ];

        $form['stripe']['stripe_secret_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Secret Key'),
            '#description' => $this->t('Enter the Stripe Secret Key.'),
            '#default_value' => $config->get('stripe_secret_key'),
            '#required' => TRUE,
        ];

        $form['stripe']['stripe_webhook_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Webhook Secret'),
            '#description' => $this->t('Enter the Stripe Webhook Secret.'),
            '#default_value' => $config->get('stripe_webhook_secret'),
            '#required' => TRUE,
        ];

        $form['stripe']['stripe_price_id_esncard'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNcard'),
            '#description' => $this->t('Enter the Stripe Price ID for the main ESNcard product.'),
            '#default_value' => $config->get('stripe_price_id_esncard'),
            '#required' => TRUE,
        ];

        $form['stripe']['stripe_price_id_processing'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for Processing Fee'),
            '#description' => $this->t('Enter the Stripe Price ID for the processing fee product.'),
            '#default_value' => $config->get('stripe_price_id_processing'),
            '#required' => TRUE,
        ];

        $form['weeztix'] = [
            '#type' => 'details',
            '#title' => $this->t('Weeztix Settings'),
            '#open' => TRUE,
            '#description' => $this->t('Configuration for the Weeztix Service.'),
        ];

        $form['weeztix']['weeztix_client_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client ID'),
            '#default_value' => $config->get('weeztix_client_id'),
            '#required' => TRUE,
        ];

        $form['weeztix']['weeztix_client_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Client Secret'),
            '#default_value' => $config->get('weeztix_client_secret'),
            '#required' => TRUE
        ];

        $form['weeztix']['weeztix_coupon_list_id'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Coupon List ID / Campaign ID'),
            '#default_value' => $config->get('weeztix_coupon_list_id'),
            '#description' => $this->t('The ID of the list where coupons should be added.'),
            '#required' => TRUE,
        ];

        if (!empty($config->get('weeztix_client_id'))) {
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

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('esn_membership_manager.settings')
            ->set('webform_id', $form_state->getValue('webform_id'))
            ->set('stripe_secret_key', $form_state->getValue('stripe_secret_key'))
            ->set('stripe_webhook_secret', $form_state->getValue('stripe_webhook_secret'))
            ->set('stripe_price_id_esncard', $form_state->getValue('stripe_price_id_esncard'))
            ->set('stripe_price_id_processing', $form_state->getValue('stripe_price_id_processing'))
            ->set('weeztix_client_id', $form_state->getValue('weeztix_client_id'))
            ->set('weeztix_client_secret', $form_state->getValue('weeztix_client_secret'))
            ->set('weeztix_coupon_list_id', $form_state->getValue('weeztix_coupon_list_id'))
            ->save();

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