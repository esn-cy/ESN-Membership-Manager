<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class WeeztixService
{
    protected MembershipSettings $membershipSettings;
    protected ClientInterface $httpClient;
    protected StateInterface $state;
    protected TimeInterface $time;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        ClientInterface               $httpClient,
        StateInterface                $state,
        LoggerChannelFactoryInterface $loggerFactory,
        TimeInterface                 $time
    )
    {
        $this->membershipSettings = new MembershipSettings($configFactory);
        $this->httpClient = $httpClient;
        $this->state = $state;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->time = $time;
    }

    public function getAuthorizationUrl(string $redirectURI, string $stateToken): ?string
    {
        $clientID = $this->membershipSettings->getWeeztixClientID();

        if (empty($clientID)) {
            return null;
        }

        $query = [
            'client_id' => $clientID,
            'redirect_uri' => $redirectURI,
            'response_type' => 'code',
            'state' => $stateToken,
        ];

        return 'https://login.weeztix.com/login?' . http_build_query($query);
    }

    public function addCoupon(string $type, string $couponCode, array $additionalData = []): bool
    {
        if ($type == 'pass') {
            $listID = $this->membershipSettings->getWeeztixPassCouponListID();
        } elseif ($type == 'card') {
            $listID = $this->membershipSettings->getWeeztixCardCouponListID();
        } else {
            $this->logger->error('Type parameter is invalid.');
            return false;
        }

        if (empty($listID)) {
            $this->logger->error('Weeztix List ID configuration is missing. Please check module settings.');
            return false;
        }

        $token = $this->getAccessToken();
        if (!$token) {
            $this->logger->error('Access token could not be fetched.');
            return false;
        }


        $listID = trim($listID);
        if (empty($listID)) return false;

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
                return true;
            } else {
                $this->logger->error('Weeztix API returned unexpected status: @status', ['@status' => $statusCode]);
                return false;
            }
        } catch (GuzzleException $e) {
            $this->logger->error('HTTP Request failed: @message', ['@message' => $e->getMessage()]);
            return false;
        }
    }

    protected function getAccessToken(): ?string
    {
        $storedToken = $this->state->get('esn_membership_manager.weeztix_access_token');
        $expiry = $this->state->get('esn_membership_manager.weeztix_token_expires');

        if ($storedToken && $expiry && $expiry > ($this->time->getRequestTime() + 300)) {
            return $storedToken;
        }

        return $this->refreshAccessToken();
    }

    protected function refreshAccessToken(): ?string
    {
        $refreshToken = $this->state->get('esn_membership_manager.weeztix_refresh_token');

        try {
            $response = $this->httpClient->request('POST', 'https://auth.weeztix.com/tokens', [
                'form_params' => [
                    'grant_type' => 'refresh_token',
                    'client_id' => $this->membershipSettings->getWeeztixClientID(),
                    'client_secret' => $this->membershipSettings->getWeeztixClientSecret(),
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

    protected function processTokenResponse(ResponseInterface $response): bool
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

    public function authorizeWithCode(string $authCode, string $redirectURI): bool
    {
        $clientID = $this->membershipSettings->getWeeztixClientID();
        $clientSecret = $this->membershipSettings->getWeeztixClientSecret();

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
                    'redirect_uri' => $redirectURI,
                    'code' => $authCode,
                ],
            ]);

            return $this->processTokenResponse($response);
        } catch (GuzzleException $e) {
            $this->logger->error('Authorization failed: @message', ['@message' => $e->getMessage()]);
            return FALSE;
        }
    }
}