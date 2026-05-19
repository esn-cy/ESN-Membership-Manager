<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Service\DiditService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class DiditController extends ControllerBase
{
    protected Connection $database;
    protected DiditService $diditService;
    protected LoggerChannelInterface $logger;

    protected array $nationalities = [];

    public function __construct(
        Connection                    $database,
        DiditService                  $diditService,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        $this->database = $database;
        $this->diditService = $diditService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var DiditService $diditService */
        $diditService = $container->get('esn_membership_manager.didit_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $diditService,
            $loggerFactory
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $sessionID = $request->query->get('verificationSessionId');

        $redirect = new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());

        if ($sessionID) {
            return $redirect;
        }

        try {
            $application = $this->database->select('esn_membership_manager_in_progress_applications', 'i')
                ->fields('i')
                ->condition('didit_session_id', $sessionID)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Didit ID Verification failed. @error', ['@error' => $e->getMessage()]);
            try {
                $this->database->update('esn_membership_manager_in_progress_applications')
                    ->fields(['didit_status' => 'Failed'])
                    ->condition('didit_session_id', $sessionID)
                    ->execute();
            } catch (Exception $updateException) {
                $this->logger->error('Failed to update Didit status to Failed. @error', ['@error' => $updateException->getMessage()]);
            }
        }

        if (empty($application)) {
            return $redirect;
        }

        $diditSession = $this->diditService->getSession($sessionID);
        if (empty($diditSession)) {
            return $redirect;
        }

        $updatedStatus = $diditSession['status'];

        $updateFields = ['didit_status' => $updatedStatus];

        if (in_array($updatedStatus, ['Approved', 'Declined', 'In Review'])) {
            $verifiedDetails = $diditSession['id_verifications'][0];
            $nationality = match ($verifiedDetails['nationality']) {
                'GBR' => 'British',
                'SHN' => 'St Helenian / Tristanian',
                'DOM' => 'Dominican',
                default => $this->getNationalities()[$verifiedDetails['nationality']] ?? 'Undetermined',
            };

            $updateFields += [
                'id_name' => $verifiedDetails['first_name'],
                'id_surname' => $verifiedDetails['last_name'],
                'id_nationality' => $nationality,
                'id_dob' => $verifiedDetails['date_of_birth'],
            ];
        }

        try {
            $this->database->update('esn_membership_manager_in_progress_applications')
                ->fields($updateFields)
                ->condition('didit_session_id', $sessionID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Didit ID Verification failed. @error', ['@error' => $e->getMessage()]);
            try {
                $this->database->update('esn_membership_manager_in_progress_applications')
                    ->fields(['didit_status' => 'Failed'])
                    ->condition('didit_session_id', $sessionID)
                    ->execute();
            } catch (Exception $updateException) {
                $this->logger->error('Failed to update Didit status. @error', ['@error' => $updateException->getMessage()]);
            }
        }

        return $redirect;
    }

    protected function getNationalities(): array
    {
        if (!empty($this->nationalities)) {
            return $this->nationalities;
        }

        try {
            $path = $this->moduleHandler()->getModule('esn_membership_manager')->getPath() . '/assets/data/nationalities.csv';
        } catch (Exception) {
            $this->nationalities = [];
            return [];
        }

        $nationalities = [];

        if (file_exists($path)) {
            if (($handle = fopen($path, 'r')) !== FALSE) {
                while (($data = fgetcsv($handle, 1000)) !== FALSE) {
                    if (empty($data[0]) || empty($data[1])) continue;
                    $nationalities[trim($data[0])] = trim($data[1]);
                }
                fclose($handle);
            }
        }
        $this->nationalities = $nationalities;
        return $nationalities;
    }
}