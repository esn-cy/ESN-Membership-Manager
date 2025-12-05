<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\ValueRange;

class GoogleSheetsService
{

    protected ConfigFactoryInterface $configFactory;
    protected LoggerChannelInterface $logger;
    protected ?GoogleClient $client = NULL;

    public function __construct(ConfigFactoryInterface $config_factory, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->configFactory = $config_factory;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    public function appendRow(array $data): bool
    {
        $client = $this->getClient();
        if (!$client) {
            return FALSE;
        }

        $config = $this->configFactory->get('esn_membership_manager.settings');
        $spreadsheetId = $config->get('google_spreadsheet_id');
        $range = $config->get('google_sheet_name') ?: 'Data' . '!A:H';

        $service = new Sheets($client);

        $values = [
            [
                $data['date'] ?? str_replace('-', '/', date('d-m-y')),
                $data['name'] ?? '',
                $data['card_number'] ?? '',
                $data['pos'] ?? '',
                $data['host'] ?? '',
                $data['nationality'] ?? '',
                $data['mop'] ?? '',
                $data['amount'] ?? 0,
            ]
        ];

        $body = new ValueRange([
            'values' => $values
        ]);

        $params = [
            'valueInputOption' => 'USER_ENTERED',
            'insertDataOption' => 'INSERT_ROWS'
        ];

        try {
            $result = $service->spreadsheets_values->append($spreadsheetId, $range, $body, $params);

            if ($result->getUpdates()->getUpdatedCells() > 0) {
                return TRUE;
            }
            return FALSE;
        } catch (Exception $e) {
            $this->logger->error('Google Sheets Append Error: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }

    protected function getClient(): ?GoogleClient
    {
        if ($this->client) {
            return $this->client;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        $clientEmail = $moduleConfig->get('google_client_email');
        $privateKey = $moduleConfig->get('google_private_key');

        if (empty($clientEmail) || empty($privateKey)) {
            $this->logger->error('Google Sheets API credentials (Email or Private Key) not configured.');
            return NULL;
        }

        $privateKey = str_replace("\\n", "\n", $privateKey);

        $authConfig = [
            'type' => 'service_account',
            'project_id' => $moduleConfig->get('google_project_id'),
            'private_key_id' => $moduleConfig->get('google_private_key_id'),
            'private_key' => $privateKey,
            'client_email' => $clientEmail,
            'client_id' => $moduleConfig->get('google_client_id'),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
            'client_x509_cert_url' => 'https://www.googleapis.com/robot/v1/metadata/x509/' . urlencode($clientEmail),
        ];

        try {
            $client = new GoogleClient();
            $client->setApplicationName('ESN Membership Manager');
            $client->setScopes([Sheets::SPREADSHEETS]);
            $client->setAuthConfig($authConfig);
            $client->setAccessType('offline');
            $this->client = $client;
            return $client;
        } catch (Exception $e) {
            $this->logger->error('Failed to initialize Google Client: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }
}