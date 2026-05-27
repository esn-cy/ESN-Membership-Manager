<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\ModuleSettings;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

class DiditService
{
    protected ConfigFactoryInterface $configFactory;
    protected Connection $database;
    protected ClientInterface $httpClient;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        Connection                    $database,
        ClientInterface               $httpClient,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->httpClient = $httpClient;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public function createVerificationSession(string $email): ?string
    {
        $moduleSettings = new ModuleSettings($this->configFactory);

        $apiKey = $moduleSettings->getDiditApiKey();
        $workflowID = $moduleSettings->getDiditWorkflowID();
        if (empty($apiKey) || empty($workflowID)) {
            $this->logger->error('Didit was not configured.');
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', 'https://verification.didit.me/v3/session', [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $apiKey,
                ],
                'body' => json_encode([
                    'workflow_id' => $workflowID,
                    'callback' => Url::fromRoute('esn_membership_manager.apply_verify_id', [], ['absolute' => TRUE])->toString(),
                    'callback_method' => 'initiator',
                ])
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Session creation failed. @error.', ['@error' => $e->getMessage()]);
            return null;
        }

        if ($response->getStatusCode() !== 201) {
            $this->logger->error('Failed to create session. Error code: @code.', ['@code' => $response->getStatusCode()]);
            return null;
        }

        $session = json_decode($response->getBody(), true);

        try {
            $this->database->update('esn_membership_manager_in_progress_applications')
                ->fields([
                    'didit_session_id' => $session['session_id'],
                    'didit_session_token' => $session['session_token'],
                    'didit_status' => $session['status']
                ])
                ->condition('email', $email)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Failed to save session. Error code: @error.', ['@error' => $e->getMessage()]);
            return null;
        }

        return $session['url'];
    }

    public function getSession(string $sessionID): ?array
    {
        $moduleSettings = new ModuleSettings($this->configFactory);

        try {
            $response = $this->httpClient->request('GET', "https://verification.didit.me/v3/session/$sessionID/decision", [
                'headers' => [
                    'x-api-key' => $moduleSettings->getDiditApiKey(),
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Session retrieval failed. @error.', ['@error' => $e->getMessage()]);
            return null;
        }

        if ($response->getStatusCode() != 200) {
            return null;
        }

        return json_decode($response->getBody(), true);
    }

    public function deleteSession(string $sessionID): bool
    {
        $moduleSettings = new ModuleSettings($this->configFactory);

        try {
            $response = $this->httpClient->request('DELETE', "https://verification.didit.me/v3/session/$sessionID/delete", [
                'headers' => [
                    'x-api-key' => $moduleSettings->getDiditApiKey(),
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Session deletion failed. @error.', ['@error' => $e->getMessage()]);
            return false;
        }

        if ($response->getStatusCode() != 204) {
            return false;
        }

        return true;
    }

    public function getPDF(string $sessionID): ?string
    {
        $moduleSettings = new ModuleSettings($this->configFactory);

        try {
            $response = $this->httpClient->request('GET', "https://verification.didit.me/v3/session/$sessionID/generate-pdf", [
                'headers' => [
                    'x-api-key' => $moduleSettings->getDiditApiKey(),
                ],
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Session PDF generation failed. @error.', ['@error' => $e->getMessage()]);
            return null;
        }

        if ($response->getStatusCode() != 200) {
            return null;
        }

        return $response->getBody()->getContents();
    }
}