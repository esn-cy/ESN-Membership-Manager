<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\omnia\Config\OmniaSettings;
use Exception;

class EmailManager
{
    protected ConfigFactoryInterface $configFactory;
    protected LoggerChannelInterface $logger;
    protected MailManagerInterface $mailManager;
    protected RendererInterface $renderer;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $loggerFactory,
        MailManagerInterface          $mailManager,
        RendererInterface             $renderer
    )
    {
        $this->configFactory = $configFactory;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->mailManager = $mailManager;
        $this->renderer = $renderer;
    }

    /**
     * Send an email using a Twig template.
     */
    public function sendEmail(string $to, string $key, array $data): void
    {
        $omniaSettings = new OmniaSettings($this->configFactory);
        $membershipSettings = new MembershipSettings($this->configFactory);

        if (str_starts_with($key, 'admin_')) {
            $to = $membershipSettings->getAdminEmailAddress();
        }

        $renderArray = [
            '#theme' => 'emm_' . $key,

            '#name' => $data['name'] ?? NULL,
            '#scheme_name' => $membershipSettings->getPassName(),
            '#logo_location' => $omniaSettings->getOrganisationLogoURL(),
            '#custom_footer' => $membershipSettings->getEmailFooter(),

            '#pass_token' => $data['pass_token'] ?? NULL,
            '#payment_link' => $data['payment_link'] ?? NULL,
            '#esncard_number' => $data['esncard_number'] ?? NULL,

            '#google_wallet_link' => $data['google_wallet_link'] ?? NULL,
            '#apple_wallet_link' => $data['apple_wallet_link'] ?? NULL,

            '#organisation_name' => $omniaSettings->getOrganisationName(),
            '#authentication_type' => $data['authentication_type'] ?? NULL,
            '#authentication_code' => $data['authentication_code'] ?? NULL,

            '#reasons' => $data['reasons'] ?? NULL,
        ];

        try {
            if (method_exists($this->renderer, 'renderInIsolation')) {
                // Drupal 10.3+
                $htmlBody = $this->renderer->renderInIsolation($renderArray);
            } else {
                // Drupal 9 / <10.3
                /** @noinspection PhpDeprecationInspection */
                $htmlBody = $this->renderer->renderPlain($renderArray);
            }
        } catch (Exception $e) {
            $this->logger->error('Email Send Error: @message', ['@message' => $e->getMessage()]);
            return;
        }

        $params = [
            'body' => $htmlBody,
            'scheme_name' => $membershipSettings->getPassName(),
            'organisation_name' => $omniaSettings->getOrganisationName()
        ];

        $this->mailManager->mail('esn_membership_manager', $key, $to, 'en', $params);
        $this->logger->info('Email Send Successfully to @email', ['@email' => $to]);
    }
}