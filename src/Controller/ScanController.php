<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScanController extends ControllerBase
{
    protected $entityTypeManager;
    protected Connection $database;
    protected LoggerChannelInterface $logger;

    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        Connection                    $database,
        LoggerChannelFactoryInterface $loggerFactory)
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entityTypeManager,
            $database,
            $loggerFactory
        );
    }

    public function scanCard(Request $request): JsonResponse
    {
        if ($request->isMethod('GET')) {
            return new JsonResponse(null, 200);
        }

        $body = json_decode($request->getContent(), TRUE) ?? [];
        $cardNumber = $body['card'] ?? NULL;

        if (empty($cardNumber)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $cardNumber) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $cardNumber) == 1;

        if (!$isESNcard && !$isPass) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        try {
            $query = $this->database->select('esn_membership_manager_applications', 'a');
            $query->fields('a');

            if ($isESNcard) {
                $query->condition('esncard_number', $cardNumber);
            } elseif ($isPass) {
                $query->condition('pass_token', $cardNumber);
            }

            $application = $query->execute()->fetchAssoc();

        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card/pass.'], 500);
        }

        if (!$application) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card/Pass not found.'], 404);
        }

        $last_scan_date = $application['date_last_scanned'] ?? NULL;

        $profileImageURL = NULL;
        $file_id = $application['face_photo_fid'] ?? NULL;

        if (!empty($file_id)) {
            try {
                /** @var FileInterface $file */
                $file = $this->entityTypeManager->getStorage('file')->load($file_id);
                $profileImageURL = $file?->createFileUrl(FALSE);
            } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
                $this->logger->warning('File ID @id was unable to be retrieved.', ['@id' => $file_id]);
            }
        }

        try {
            $updateFields = [];
            if ($isESNcard) {
                $updateFields['approval_status'] = 'Delivered';
            }
            $updateFields['date_last_scanned'] = (new DrupalDateTime())->format('Y-m-d H:i:s');

            $this->database->update('esn_membership_manager_applications')
                ->fields($updateFields)
                ->condition('id', $application['id'])
                ->execute();

        } catch (Exception $e) {
            $this->logger->error('Scan update failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update last scan date.'], 500);
        }

        return new JsonResponse([
            'name' => $application['name'],
            'surname' => $application['surname'],
            'nationality' => $application['nationality'],
            'mobilityStatus' => $application['mobility_status'],
            'datePaid' => !empty($application['date_paid']) ? (new DrupalDateTime($application['date_paid']))->format('Y-m-d') : null,
            'dateApproved' => !empty($application['date_approved']) ? (new DrupalDateTime($application['date_approved']))->format('Y-m-d') : null,
            'lastScanDate' => !empty($last_scan_date) ? (new DrupalDateTime($last_scan_date))->format('Y-m-d') : null,
            'profileImageURL' => $profileImageURL,
        ], 200);
    }
}