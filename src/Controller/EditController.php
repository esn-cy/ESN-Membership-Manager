<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Config\ModuleSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class EditController extends ControllerBase
{
    protected Connection $database;
    protected FileService $fileService;
    protected LoggerChannelInterface $logger;
    protected GoogleService $googleService;
    protected AppleWalletService $appleWalletService;

    public function __construct(
        Connection                    $database,
        FileService $fileService,
        LoggerChannelFactoryInterface $loggerFactory,
        GoogleService                 $googleService,
        AppleWalletService            $appleWalletService,
    )
    {
        $this->database = $database;
        $this->fileService = $fileService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->googleService = $googleService;
        $this->appleWalletService = $appleWalletService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var AppleWalletService $appleWalletService */
        $appleWalletService = $container->get('esn_membership_manager.apple_wallet_service');

        return new static(
            $database,
            $fileService,
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

        $this->config('');
        $moduleSettings = new ModuleSettings($this->configFactory);

        try {
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_application');

            $application = $storage->load($applicationID);
        } catch (Exception $e) {
            $this->logger->error('Select query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the application.'], 500);
        }

        if (empty($application)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
        }

        $hasVerifiedEmail = $application->getValue(ApplicationField::HasVerifiedEmail);
        $hasVerifiedID = $application->getValue(ApplicationField::HasVerifiedID);
        $hasVerifiedStatus = $application->getValue(ApplicationField::HasVerifiedStatus);

        foreach (ApplicationField::cases() as $field) {
            if ($field->isReadOnly($hasVerifiedEmail, $hasVerifiedID, $hasVerifiedStatus)) {
                continue;
            }

            if (isset($body[$field->value])) {
                if ($field->value == ApplicationField::DateOfBirth->value) {
                    $application->setValue($field, DrupalDateTime::createFromFormat('d/m/Y', $body[$field->value])->format('Y-m-d'));
                } else {
                    $application->setValue($field, $body[$field->value]);
                }
            }
        }

        $application->setValue(ApplicationField::DateLastModified, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

        try {
            $application->save();
        } catch (Exception $e) {
            $this->logger->error('Update query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the application.'], 500);
        }

        if (!in_array($application->getApprovalStatus(), ['Pending', 'Rejected', 'Blacklisted']) && $moduleSettings->getGoogleWalletSwitch()) {
            if ($application->getValue(ApplicationField::HasESNcard)) {
                try {
                    $this->googleService->updateApplicationObject($application, 'card');
                } catch (Exception|GuzzleException $e) {
                    $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
                }
            }
            try {
                $this->googleService->updateApplicationObject($application, 'pass');
            } catch (Exception|GuzzleException $e) {
                $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
            }
        }

        if (!in_array($application->getApprovalStatus(), ['Pending', 'Rejected', 'Blacklisted']) && $moduleSettings->getAppleWalletSwitch()) {
            try {
                $query = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                    ->fields('w', ['push_token']);

                $orGroup = $query->orConditionGroup()
                    ->condition('serial_number', 'free_pass-' . $application->id())
                    ->condition('serial_number', 'esncard-' . $application->id());

                $pushTokens = $query->condition($orGroup)
                    ->execute()
                    ->fetchCol();

                if (!empty($pushTokens)) {
                    foreach (array_unique($pushTokens) as $pushToken) {
                        $this->appleWalletService->sendApplicationUpdateNotification($pushToken);
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

        $this->config('');
        $moduleSettings = new ModuleSettings($this->configFactory);

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
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_application');

            $application = $storage->load($applicationID);

            if (empty($application)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
            }

            if (empty($application->getFacePhoto())) {
                return new JsonResponse(['status' => 'error', 'message' => 'No face photo was found for this application.'], 404);
            }

            if (!$this->fileService->replaceFileData($application->getFacePhoto()->id(), $imageData)) {
                return new JsonResponse(['status' => 'error', 'message' => 'Unable to write file.'], 500);
            }

            if (!in_array($application->getApprovalStatus(), ['Pending', 'Rejected', 'Blacklisted']) && $moduleSettings->getGoogleWalletSwitch()) {
                try {
                    $this->googleService->updateApplicationObject($application, 'card');
                } catch (Exception|GuzzleException $e) {
                    $this->logger->error('Google Wallet Update Failed: @message', ['@message' => $e->getMessage()]);
                }
            }

            if (!in_array($application->getApprovalStatus(), ['Pending', 'Rejected', 'Blacklisted']) && $moduleSettings->getAppleWalletSwitch()) {
                try {
                    $pushTokens = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                        ->fields('w', ['push_token'])
                        ->condition('serial_number', 'esncard-' . $applicationID)
                        ->execute()
                        ->fetchCol();

                    if (!empty($pushTokens)) {
                        foreach (array_unique($pushTokens) as $pushToken) {
                            $this->appleWalletService->sendApplicationUpdateNotification($pushToken);
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