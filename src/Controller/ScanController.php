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
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScanController extends ControllerBase
{
    protected $entityTypeManager;
    protected Connection $database;
    protected GoogleService $googleService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        Connection                    $database,
        GoogleService $googleService,
        LoggerChannelFactoryInterface $loggerFactory)
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->database = $database;
        $this->googleService = $googleService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entityTypeManager,
            $database,
            $googleService,
            $loggerFactory
        );
    }

    public function scanCard(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $cardNumber = $body['card'] ?? NULL;

        if (empty($cardNumber)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $cardNumber) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $cardNumber) == 1;
        $isGuest = preg_match("/^GUEST[A-F0-9]{27}$/", $cardNumber) == 1;

        if (!$isESNcard && !$isPass && !$isGuest) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        if ($isGuest) {
            return $this->scanGuest($cardNumber);
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

        if ($application['approval_status'] == 'Blacklisted')
            return new JsonResponse([
                'name' => 'BLACKLISTED',
                'surname' => 'BLACKLISTED',
                'nationality' => '',
                'mobilityStatus' => '',
                'datePaid' => '',
                'dateApproved' => '',
                'lastScanDate' => '',
                'profileImageURL' => '',
            ], 200);

        $lastScanDate = $application['date_last_scanned'] ?? NULL;

        $profileImageURL = NULL;
        $fileID = $application['face_photo_fid'] ?? NULL;

        if (!empty($fileID)) {
            try {
                /** @var FileInterface $file */
                $file = $this->entityTypeManager->getStorage('file')->load($fileID);
                $profileImageURL = $file?->createFileUrl(FALSE);
            } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
                $this->logger->warning('File ID @id was unable to be retrieved.', ['@id' => $fileID]);
            }
        }

        try {
            $updateFields = [];
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
            'lastScanDate' => !empty($lastScanDate) ? (new DrupalDateTime($lastScanDate))->format('Y-m-d') : null,
            'profileImageURL' => $profileImageURL,
        ], 200);
    }

    protected function scanGuest($cardNumber): JsonResponse
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        try {
            $query = $this->database->select('esn_membership_manager_guest_passes', 'g');
            $query->addField('g', 'id', 'id');
            $query->addField('g', 'name', 'guest_name');
            $query->addField('g', 'surname', 'guest_surname');
            $query->addField('g', 'date_approved', 'date_approved');
            $query->addField('g', 'date_redeemed', 'date_redeemed');
            $query->addField('a', 'name', 'referer_name');
            $query->addField('a', 'surname', 'referer_surname');
            $query->addField('a', 'mobility_status', 'referer_mobility_status');
            $query->condition('g.guest_pass_token', $cardNumber);
            $query->join('esn_membership_manager_applications', 'a', 'a.id = g.referrer_id');
            $application = $query->execute()->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the guest pass.'], 500);
        }

        if (!$application) {
            return new JsonResponse(['status' => 'error', 'message' => 'Guest Pass not found.'], 404);
        }

        try {
            $updateFields = [];
            $updateFields['date_redeemed'] = (new DrupalDateTime())->format('Y-m-d H:i:s');

            $this->database->update('esn_membership_manager_guest_passes')
                ->fields($updateFields)
                ->condition('id', $application['id'])
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Scan update failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update redeemed date.'], 500);
        }

        if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
            try {
                $this->googleService->deleteObject($application['id'], 'guest');
            } catch (Exception) {
            }
        }

        return new JsonResponse([
            'name' => $application['guest_name'],
            'surname' => $application['guest_surname'],
            'refererName' => $application['referer_name'],
            'refererSurname' => $application['referer_surname'],
            'refererMobilityStatus' => $application['referer_mobility_status'],
            'dateApproved' => !empty($application['date_approved']) ? (new DrupalDateTime($application['date_approved']))->format('Y-m-d') : null,
            'dateRedeemed' => !empty($application['date_redeemed']) ? (new DrupalDateTime($application['date_redeemed']))->format('Y-m-d') : null,
        ], 200);
    }
}