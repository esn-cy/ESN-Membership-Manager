<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Exception;

class EmailManager
{
    protected ConfigFactoryInterface $configFactory;
    protected LoggerChannelInterface $logger;
    protected MailManagerInterface $mailManager;
    protected RendererInterface $renderer;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $logger_factory,
        MailManagerInterface          $mail_manager,
        RendererInterface             $renderer
    )
    {
        $this->configFactory = $configFactory;
        $this->logger = $logger_factory->get('esn_membership_manager');
        $this->mailManager = $mail_manager;
        $this->renderer = $renderer;
    }

    /**
     * Send an email using a Twig template.
     */
    public function sendEmail($to, $key, $data): void
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $scheme_name = $moduleConfig->get('scheme_name');

        if (!empty($data['google_wallet_link'])) {
            $googleWalletButton = "<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" role=\"presentation\" width=\"100%\">
                            <tbody>
                            <tr>
                                <td style=\"padding: 9px 20px;\" width=\"100%\" align=\"center\">
                                    <a href=\"{$data['google_wallet_link']}\">
                                        <img src=\"https://esncy.org/sites/default/files/2025-12/add_to_google_wallet_badge.png\" alt=\"Add to Google Wallet\" height=\"45\">
                                    </a>
                                </td>
                            </tr>
                            </tbody>
                        </table>";
        }

        $render_array = [
            '#theme' => 'emm_' . $key,

            '#name' => $data['name'],
            '#scheme_name' => $scheme_name,
            '#logo_location' => $moduleConfig->get('logo_url'),
            '#custom_footer' => $moduleConfig->get('email_footer'),

            '#user_token' => $data['token'] ?? NULL,
            '#payment_link' => $data['payment_link'] ?? NULL,
            '#esncard_number' => $data['esncard_number'] ?? NULL,

            '#google_wallet_button' => $googleWalletButton ?? NULL,
        ];

        try {
            if (method_exists($this->renderer, 'renderInIsolation')) {
                // Drupal 10.3+
                $html_body = $this->renderer->renderInIsolation($render_array);
            } else {
                // Drupal 9 / <10.3
                $html_body = $this->renderer->renderPlain($render_array);
            }
        } catch (Exception $e) {
            $this->logger->error('Email Send Error: @message', ['@message' => $e->getMessage()]);
            return;
        }

        $params = [
            'body' => $html_body,
            'scheme_name' => $scheme_name,
        ];

        $this->mailManager->mail('esn_membership_manager', $key, $to, 'en', $params);
        $this->logger->info('Email Send Successfully to @email', ['@email' => $to]);
    }
}