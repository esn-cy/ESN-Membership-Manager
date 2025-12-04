<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class WeeztixApiService
{
    protected ConfigFactoryInterface $configFactory;
    protected ClientInterface $httpClient;
    protected StateInterface $state;
    protected LoggerChannelInterface $logger;
    protected TimeInterface $time;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        ClientInterface               $httpClient,
        StateInterface                $state,
        LoggerChannelFactoryInterface $loggerFactory,
        TimeInterface                 $time
    )
    {
        $this->configFactory = $configFactory;
        $this->httpClient = $httpClient;
        $this->state = $state;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->time = $time;
    }

    public function getAuthorizationUrl(string $redirect_uri, string $state_token): ?string
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $client_id = $moduleConfig->get('weeztix_client_id');

        if (!$client_id) {
            return NULL;
        }

        $query = [
            'client_id' => $client_id,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'state' => $state_token,
        ];

        return 'https://login.weeztix.com/login?' . http_build_query($query);
    }

    public function addCoupon(string $coupon_code, array $additional_data = []): bool
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $list_id = $moduleConfig->get('weeztix_coupon_list_id');

        if (empty($list_id)) {
            $this->logger->error('Weeztix List ID configuration is missing. Please check module settings.');
            return FALSE;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->logger->error('Access token could not be fetched.');
            return FALSE;
        }


        $list_id = trim($list_id);
        if (empty($list_id)) return FALSE;

        $code_object = array_merge([
            'code' => $coupon_code
        ], $additional_data);

        $payload = [
            'codes' => [
                $code_object
            ]
        ];

        try {
            $response = $this->httpClient->request('PUT', "https://api.weeztix.com/coupon/$list_id/codes", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $status_code = $response->getStatusCode();

            if ($status_code >= 200 && $status_code < 300) {
                $this->logger->info('Successfully added coupon @code to Weeztix.', ['@code' => $coupon_code]);
                return TRUE;
            } else {
                $this->logger->error('Weeztix API returned unexpected status: @status', ['@status' => $status_code]);
                return FALSE;
            }
        } catch (GuzzleException $e) {
            $this->logger->error('HTTP Request failed: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }

    protected function getAccessToken()
    {
        $stored_token = $this->state->get('esn_membership_manager.weeztix_access_token');
        $expiry = $this->state->get('esn_membership_manager.weeztix_token_expires');

        if ($stored_token && $expiry && $expiry > ($this->time->getRequestTime() + 300)) {
            return $stored_token;
        }

        return $this->refreshAccessToken();
    }

    protected function refreshAccessToken()
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $client_id = $moduleConfig->get('weeztix_client_id');
        $client_secret = $moduleConfig->get('weeztix_client_secret');
        $refresh_token = $this->state->get('esn_membership_manager.weeztix_refresh_token');

        try {
            $response = $this->httpClient->request('POST', 'https://auth.weeztix.com/tokens', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token
                ],
            ]);

            if ($this->processTokenResponse($response)) {
                return $this->state->get('esn_membership_manager.weeztix_access_token');
            }
            $this->logger->error('No auth token could be fetched.');

            return NULL;
        } catch (GuzzleException $e) {
            $this->logger->error('Token refresh failed: @message', ['@message' => $e->getMessage()]);
            return NULL;
        }
    }

    protected function processTokenResponse($response): bool
    {
        $data = json_decode($response->getBody(), TRUE);

        if (isset($data['access_token'])) {
            $token = $data['access_token'];
            $expires_in = $data['expires_in'] ?? 3600;

            $this->state->set('esn_membership_manager.weeztix_access_token', $token);
            $this->state->set('esn_membership_manager.weeztix_token_expires', $this->time->getRequestTime() + $expires_in);

            if (isset($data['refresh_token'])) {
                $this->state->set('esn_membership_manager.weeztix_refresh_token', $data['refresh_token']);
            }
            return TRUE;
        }
        return FALSE;
    }

    public function authorizeWithCode(string $auth_code, string $redirect_uri): bool
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $client_id = $moduleConfig->get('weeztix_client_id');
        $client_secret = $moduleConfig->get('weeztix_client_secret');

        if (!$client_id || !$client_secret) {
            $this->logger->error('Weeztix Authentication configuration is missing. Please check module settings.');
            return FALSE;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://auth.weeztix.com/tokens', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'redirect_uri' => $redirect_uri,
                    'code' => $auth_code,
                ],
            ]);

            return $this->processTokenResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Authorization failed: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }
}