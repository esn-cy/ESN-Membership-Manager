<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\esn_membership_manager\Service\GoogleService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ScanController extends ControllerBase
{
    protected FileService $fileService;
    protected GoogleService $googleService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        FileService                   $fileService,
        GoogleService                 $googleService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->fileService = $fileService;
        $this->googleService = $googleService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $fileService,
            $googleService,
            $loggerFactory
        );
    }

    public function scanCard(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $identifier = $body['card'] ?? NULL;

        if (empty($identifier)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $identifier) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $identifier) == 1;
        $isGuest = preg_match("/^GUEST[A-F0-9]{27}$/", $identifier) == 1;

        if (!$isESNcard && !$isPass && !$isGuest) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        if ($isGuest) {
            return $this->scanGuest($identifier);
        }

        try {
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_application');

            if ($isESNcard) {
                $application = $storage->getByESNcard($identifier);
            } elseif ($isPass) {
                $application = $storage->getByPassToken($identifier);
            }
        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card/pass.'], 500);
        }

        if (empty($application)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card/Pass not found.'], 404);
        }

        if ($application->getValue(ApplicationField::ApprovalStatus) == 'Blacklisted')
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

        $profileImageURL = $this->fileService->getFileURL(!empty($application->getFacePhoto()) ? $application->getFacePhoto()->id() : null);

        $application->updateLastScanned();

        try {
            $application->save();
        } catch (Exception $e) {
            $this->logger->error('Scan update failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update last scan date.'], 500);
        }

        return new JsonResponse([
            'name' => $application->getValue(ApplicationField::Name),
            'surname' => $application->getValue(ApplicationField::Surname),
            'nationality' => $application->getValue(ApplicationField::Nationality),
            'mobilityStatus' => $application->getValue(ApplicationField::MobilityStatus),
            'datePaid' => !empty($application->getDatePaid()) ? $application->getDatePaid()->format('Y-m-d') : null,
            'dateApproved' => !empty($application->getDateApproved()) ? $application->getDateApproved()->format('Y-m-d') : null,
            'lastScanDate' => !empty($application->getDateLastScanned()) ? $application->getDateLastScanned()->format('Y-m-d') : null,
            'profileImageURL' => $profileImageURL,
        ], 200);
    }

    protected function scanGuest(string $identifier): JsonResponse
    {
        $moduleConfig = $this->config('esn_membership_manager.settings');

        try {
            /** @var GuestPassStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_guest');

            $guestPass = $storage->getByPassToken($identifier);
            $referrer = $guestPass?->getReferer();
        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the guest pass.'], 500);
        }

        if (empty($guestPass) || empty($referrer)) {
            throw new NotFoundHttpException('Guest Pass not found.', null, 404);
        }

        try {
            $guestPass->setValue(GuestPassField::DateRedeemed, (new DrupalDateTime())->format('Y-m-d\TH:i:s'));

            $guestPass->save();
        } catch (Exception $e) {
            $this->logger->error('Scan update failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update redeemed date.'], 500);
        }

        if ($moduleConfig->get('switch_google_wallet') ?? FALSE) {
            try {
                $this->googleService->deleteObject($guestPass->id(), 'guest');
            } catch (Exception) {
            }
        }

        return new JsonResponse([
            'name' => $guestPass->getValue(GuestPassField::Name),
            'surname' => $guestPass->getValue(GuestPassField::Surname),
            'refererName' => $referrer->getValue(ApplicationField::Name),
            'refererSurname' => $referrer->getValue(ApplicationField::Surname),
            'refererMobilityStatus' => $referrer->getValue(ApplicationField::MobilityStatus),
            'dateApproved' => !empty($guestPass->getDateApproved()) ? $guestPass->getDateApproved()->format('Y-m-d') : null,
            'dateRedeemed' => !empty($guestPass->getDateRedeemed()) ? $guestPass->getDateRedeemed()->format('Y-m-d') : null,
        ], 200);
    }
}