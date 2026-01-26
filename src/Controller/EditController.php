<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EditController extends ControllerBase
{
    protected Connection $database;
    protected LoggerChannelInterface $logger;

    public function __construct(
        Connection                    $database,
        LoggerChannelFactoryInterface $loggerFactory)
    {
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $loggerFactory
        );
    }

    public function editApplication(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $applicationID = $body['id'] ?? NULL;

        if (empty($applicationID)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ID was provided.'], 400);
        }

        if (!is_numeric($applicationID)) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid ID was provided.'], 400);
        }

        $allowedFields = [
            'name',
            'surname',
            'email',
            'nationality',
            'dob',
            'mobility_status',
            'host_institution',
            'pass_token',
            'esncard_number',
            'date_last_scanned'
        ];

        $fieldsToUpdate = [];

        foreach ($allowedFields as $field) {
            if (isset($body[$field])) {
                $fieldsToUpdate[$field] = $body[$field];
            }
        }

        if (empty($fieldsToUpdate)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No changes detected.'], 400);
        }

        try {
            $application = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a')
                ->condition('id', $applicationID)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Select query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the application.'], 500);
        }

        if (empty($application)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields($fieldsToUpdate)
                ->condition('id', $applicationID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Update query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the application.'], 500);
        }

        return new JsonResponse(['status' => 'success', 'message' => 'Application updated successfully.'], 200);
    }
}