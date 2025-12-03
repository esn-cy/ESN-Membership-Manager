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
use Drupal\webform\WebformSubmissionInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScanController extends ControllerBase
{
    protected $entityTypeManager;
    protected Connection $database;
    protected LoggerChannelInterface $logger;

    public function __construct(EntityTypeManagerInterface $entity_type_manager, Connection $database, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->entityTypeManager = $entity_type_manager;
        $this->database = $database;
        $this->logger = $logger_factory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entity_type_manager */
        $entity_type_manager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entity_type_manager,
            $database,
            $loggerFactory
        );
    }

    public function scanCard(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse();
        }

        $body = json_decode($request->getContent(), TRUE) ?? [];
        $card_number = $body['card'] ?? NULL;

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $is_esncard = preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) == 1;
        $is_esn_cyprus_pass = preg_match("/ESNCYTKNESNCYTKN\d*/", $card_number) == 1;

        if (!$is_esncard && !$is_esn_cyprus_pass) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        try {
            $storage = $this->entityTypeManager->getStorage('webform_submission');
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Webform module was unavailable.'], 500);
        }

        try {
            $query = $this->database->select('webform_submission', 'ws');
            $query->join('webform_submission_data', 'wsd', 'ws.sid = wsd.sid');
            $query->fields('ws', ['sid']);
            $query->condition('ws.webform_id', 'esn_cyprus_pass');

            if ($is_esncard) {
                $query->condition('wsd.name', 'esncard_number');
            } else {
                $query->condition('wsd.name', 'user_token');
            }
            $query->condition('wsd.value', $card_number);

            $query->range(0, 1);
            $sid = $query->execute()->fetchField();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
        }

        if (empty($sid) && $is_esncard) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 404);
        }

        if (empty($sid) && $is_esn_cyprus_pass) {
            $sid = str_replace('ESNCYTKNESNCYTKN', '', $card_number);
        }

        /** @var WebformSubmissionInterface $submission */
        $submission = $storage->load($sid);
        $data = $submission->getData();

        $last_scan_date = $data['last_scan_date'] ?? NULL;

        $profile_image_url = NULL;
        $file_id = $data['profile_image_esncard'] ?? NULL;

        if (!empty($file_id)) {
            try {
                /** @var FileInterface $file */
                $file = $this->entityTypeManager->getStorage('file')->load($file_id);
                $profile_image_url = $file?->createFileUrl(FALSE);
            } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
                $this->logger->warning('File ID @id was unable to be retrieved.', ['@id' => $file_id]);
            }
        }

        try {
            if ($is_esncard) {
                $submission->setElementData('approval_status', 'Delivered');
            }
            $submission->setElementData('pass_is_enabled', 2);
            $submission->setElementData('last_scan_date', (new DrupalDateTime())->format('Y-m-d H:i:s'));
            $submission->save();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update last scan date.'], 500);
        }

        return new JsonResponse([
            'name' => $data['name'],
            'surname' => $data['surname'],
            'nationality' => $data['country_origin'], //TODO Change to Nationality field once available
            'occupation' => $data['occupation'],
            'datePaid' => !empty($data['date_paid']) ? (new DrupalDateTime($data['date_paid']))->format('Y-m-d') : null,
            'dateApproved' => !empty($data['date_approved']) ? (new DrupalDateTime($data['date_approved']))->format('Y-m-d') : (new DrupalDateTime('@' . $submission->getCompletedTime()))->format('Y-m-d'),
            'lastScanDate' => !empty($last_scan_date) ? (new DrupalDateTime($last_scan_date))->format('Y-m-d') : null,
            'profileImageURL' => $profile_image_url,
        ], 200);
    }
}