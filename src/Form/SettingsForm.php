<?php

namespace Drupal\esn_cyprus_pass_validation\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Defines a configuration form for ESN Cyprus Pass settings.
 */
class SettingsForm extends ConfigFormBase
{

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_cyprus_pass_validation_settings_form';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $config = $this->config('esn_cyprus_pass_validation.settings');

        $form['stripe_secret_key'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Secret Key'),
            '#description' => $this->t('Enter the Stripe Secret Key.'),
            '#default_value' => $config->get('stripe_secret_key'),
            '#required' => TRUE,
        ];

        $form['stripe_webhook_secret'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Webhook Secret'),
            '#description' => $this->t('Enter the Stripe Webhook Secret.'),
            '#default_value' => $config->get('stripe_webhook_secret'),
            '#required' => TRUE,
        ];

        $form['stripe_price_id_esncard'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for ESNcard'),
            '#description' => $this->t('Enter the Stripe Price ID for the main ESNcard product.'),
            '#default_value' => $config->get('stripe_price_id_esncard'),
            '#required' => TRUE,
        ];

        $form['stripe_price_id_processing'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Stripe Price ID for Processing Fee'),
            '#description' => $this->t('Enter the Stripe Price ID for the processing fee product.'),
            '#default_value' => $config->get('stripe_price_id_processing'),
            '#required' => TRUE,
        ];

        return parent::buildForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $this->config('esn_cyprus_pass_validation.settings')
            ->set('stripe_secret_key', $form_state->getValue('stripe_secret_key'))
            ->set('stripe_webhook_secret', $form_state->getValue('stripe_webhook_secret'))
            ->set('stripe_price_id_esncard', $form_state->getValue('stripe_price_id_esncard'))
            ->set('stripe_price_id_processing', $form_state->getValue('stripe_price_id_processing'))
            ->save();

        parent::submitForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames(): array
    {
        return ['esn_cyprus_pass_validation.settings'];
    }
}