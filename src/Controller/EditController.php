<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use DateTime;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EditController extends ControllerBase
{
    protected $configFactory;
    protected $entityTypeManager;
    protected Connection $database;
    protected FileRepositoryInterface $fileRepository;
    protected LoggerChannelInterface $logger;
    protected GoogleService $googleService;
    protected AppleWalletService $appleWalletService;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        EntityTypeManagerInterface    $entityTypeManager,
        Connection                    $database,
        FileRepositoryInterface       $fileRepository,
        LoggerChannelFactoryInterface $loggerFactory,
        GoogleService                 $googleService,
        AppleWalletService            $appleWalletService,
    )
    {
        $this->configFactory = $configFactory;
        $this->entityTypeManager = $entityTypeManager;
        $this->database = $database;
        $this->fileRepository = $fileRepository;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->googleService = $googleService;
        $this->appleWalletService = $appleWalletService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var FileRepositoryInterface $fileRepository */
        $fileRepository = $container->get('file.repository');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var AppleWalletService $appleWalletService */
        $appleWalletService = $container->get('esn_membership_manager.apple_wallet_service');

        return new static(
            $configFactory,
            $entityTypeManager,
            $database,
            $fileRepository,
            $loggerFactory,
            $googleService,
            $appleWalletService
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

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

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
                if ($field == "dob") {
                    $fieldsToUpdate[$field] = DateTime::createFromFormat('d/m/Y', $body[$field])->format('Y-m-d');
                } else {
                    $fieldsToUpdate[$field] = $body[$field];
                }
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

        if (!empty($fieldsToUpdate['name']) || !empty($fieldsToUpdate['surname'])) {
            if (empty($fieldsToUpdate['name'])) {
                $fieldsToUpdate['name'] = $application['name'];
            }
            if (empty($fieldsToUpdate['surname'])) {
                $fieldsToUpdate['surname'] = $application['surname'];
            }
        }

        $fieldsToUpdate['date_last_modified'] = (new DrupalDateTime())->format('Y-m-d H:i:s');

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields($fieldsToUpdate)
                ->condition('id', $applicationID)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Update query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the application.'], 500);
        }

        $updatedApplication = array_merge($application, $fieldsToUpdate);

        if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
            if ($application['esncard']) {
                try {
                    $this->googleService->updateObject($updatedApplication, 'card');
                } catch (Exception|GuzzleException $e) {
                    $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
                }
            }
            try {
                $this->googleService->updateObject($updatedApplication, 'pass');
            } catch (Exception|GuzzleException $e) {
                $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
            }
        }

        if ($moduleConfig->get('switch_apple_wallet') ?? FALSE) {
            try {
                $query = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                    ->fields('w', ['push_token']);

                $orGroup = $query->orConditionGroup()
                    ->condition('serial_number', 'free_pass-' . $updatedApplication['id'])
                    ->condition('serial_number', 'esncard-' . $updatedApplication['id']);

                $pushTokens = $query->condition($orGroup)
                    ->execute()
                    ->fetchCol();

                if (!empty($pushTokens)) {
                    foreach (array_unique($pushTokens) as $pushToken) {
                        $this->appleWalletService->sendUpdateNotification($pushToken);
                    }
                }
            } catch (Exception $e) {
                $this->logger->error('Apple Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
            }
        }

        return new JsonResponse(['status' => 'success', 'message' => 'Application updated successfully.'], 200);
    }

    public function cropPhoto(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $applicationID = $body['id'] ?? NULL;
        $croppedImage = $body['image'] ?? NULL;

        if (empty($applicationID)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ID was provided.'], 400);
        }

        if (!is_numeric($applicationID)) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid ID was provided.'], 400);
        }

        if (empty($croppedImage)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No cropped image was provided.'], 400);
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        if (str_contains($croppedImage, 'base64,')) {
            $croppedImage = explode('base64,', $croppedImage)[1];
        } else {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid cropped image was provided.'], 400);
        }

        $imageData = base64_decode($croppedImage);
        if ($imageData === FALSE) {
            return new JsonResponse(['status' => 'error', 'message' => 'Failed to decode image.'], 400);
        }

        try {
            $application = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['face_photo_fid'])
                ->condition('id', $applicationID)
                ->execute()
                ->fetchAssoc();

            if (empty($application)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
            }

            if (empty($application['face_photo_fid'])) {
                return new JsonResponse(['status' => 'error', 'message' => 'No face photo was found for this application.'], 404);
            }

            $fid = $application['face_photo_fid'];
            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fid);

            if (!$file) {
                return new JsonResponse(['status' => 'error', 'message' => 'File entity not found.'], 404);
            }

            if (class_exists('\Drupal\Core\File\FileExists')) {
                // Drupal 10.3+
                /** @noinspection PhpFullyQualifiedNameUsageInspection */
                // @phpstan-ignore-next-line
                $this->fileRepository->writeData($imageData, $file->getFileUri(), \Drupal\Core\File\FileExists::Replace);
            } else {
                // Drupal 9 / <10.3
                /** @noinspection PhpFullyQualifiedNameUsageInspection */
                // @phpstan-ignore-next-line
                $this->fileRepository->writeData($imageData, $file->getFileUri(), \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
            }

            if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
                try {
                    $this->googleService->updateObject($application, 'card');
                } catch (Exception|GuzzleException $e) {
                    $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
                }
            }

            if ($moduleConfig->get('switch_apple_wallet') ?? FALSE) {
                try {
                    $pushTokens = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                        ->fields('w', ['push_token'])
                        ->condition('serial_number', 'esncard-' . $application['id'])
                        ->execute()
                        ->fetchCol();

                    if (!empty($pushTokens)) {
                        foreach (array_unique($pushTokens) as $pushToken) {
                            $this->appleWalletService->sendUpdateNotification($pushToken);
                        }
                    }
                } catch (Exception $e) {
                    $this->logger->error('Apple Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
                }
            }

            return new JsonResponse(['status' => 'success', 'message' => 'Photo cropped successfully.'], 200);
        } catch (Exception $e) {
            $this->logger->error('Cropping failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'An error occurred while cropping the photo.'], 500);
        }
    }
}