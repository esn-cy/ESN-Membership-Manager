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
        $clientID = $moduleConfig->get('weeztix_client_id');

        if (!$clientID) {
            return NULL;
        }

        $query = [
            'client_id' => $clientID,
            'redirect_uri' => $redirect_uri,
            'response_type' => 'code',
            'state' => $state_token,
        ];

        return 'https://login.weeztix.com/login?' . http_build_query($query);
    }

    public function addCoupon(string $couponCode, array $additionalData = []): bool
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $listID = $moduleConfig->get('weeztix_coupon_list_id');

        if (empty($listID)) {
            $this->logger->error('Weeztix List ID configuration is missing. Please check module settings.');
            return FALSE;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->logger->error('Access token could not be fetched.');
            return FALSE;
        }


        $listID = trim($listID);
        if (empty($listID)) return FALSE;

        $codeObject = array_merge([
            'code' => $couponCode
        ], $additionalData);

        $payload = [
            'codes' => [
                $codeObject
            ]
        ];

        try {
            $response = $this->httpClient->request('PUT', "https://api.weeztix.com/coupon/$listID/codes", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Successfully added coupon @code to Weeztix.', ['@code' => $couponCode]);
                return TRUE;
            } else {
                $this->logger->error('Weeztix API returned unexpected status: @status', ['@status' => $statusCode]);
                return FALSE;
            }
        } catch (GuzzleException $e) {
            $this->logger->error('HTTP Request failed: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }

    protected function getAccessToken()
    {
        $storedToken = $this->state->get('esn_membership_manager.weeztix_access_token');
        $expiry = $this->state->get('esn_membership_manager.weeztix_token_expires');

        if ($storedToken && $expiry && $expiry > ($this->time->getRequestTime() + 300)) {
            return $storedToken;
        }

        return $this->refreshAccessToken();
    }

    protected function refreshAccessToken()
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $clientID = $moduleConfig->get('weeztix_client_id');
        $clientSecret = $moduleConfig->get('weeztix_client_secret');
        $refreshToken = $this->state->get('esn_membership_manager.weeztix_refresh_token');

        try {
            $response = $this->httpClient->request('POST', 'https://auth.weeztix.com/tokens', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientID,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken
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
            $expiresIn = $data['expires_in'] ?? 3600;

            $this->state->set('esn_membership_manager.weeztix_access_token', $token);
            $this->state->set('esn_membership_manager.weeztix_token_expires', $this->time->getRequestTime() + $expiresIn);

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
        $clientID = $moduleConfig->get('weeztix_client_id');
        $clientSecret = $moduleConfig->get('weeztix_client_secret');

        if (!$clientID || !$clientSecret) {
            $this->logger->error('Weeztix Authentication configuration is missing. Please check module settings.');
            return FALSE;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://auth.weeztix.com/tokens', [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'client_id' => $clientID,
                    'client_secret' => $clientSecret,
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