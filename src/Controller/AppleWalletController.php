<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use DateTime;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Site\Settings;
use Drupal\esn_membership_manager\Service\AppleWalletService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppleWalletController extends ControllerBase
{
    protected Settings $settings;
    protected AppleWalletService $appleWalletService;
    protected Connection $database;
    protected LoggerChannelInterface $logger;

    public function __construct(
        Settings $settings,
        AppleWalletService            $appleWalletService,
        Connection                    $database,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->settings = $settings;
        $this->appleWalletService = $appleWalletService;
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Settings $settings */
        $settings = $container->get('settings');

        /** @var AppleWalletService $appleWalletService */
        $appleWalletService = $container->get('esn_membership_manager.apple_wallet_service');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $settings,
            $appleWalletService,
            $database,
            $loggerFactory
        );
    }

    /**
     * @throws BadRequestHttpException
     * @throws HttpException
     * @throws NotFoundHttpException
     */
    public function download(string $identifier): Response
    {
        if (empty($identifier)) {
            throw new BadRequestHttpException('No identifier was provided.', null, 400);
        }

        $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $identifier) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $identifier) == 1;
        $isGuest = preg_match("/^GUEST[A-F0-9]{27}$/", $identifier) == 1;

        if (!$isESNcard && !$isPass && !$isGuest) {
            throw new BadRequestHttpException('An invalid identifier was provided.', null, 400);
        }

        if ($isGuest) {
            return $this->downloadGuest($identifier);
        }

        try {
            $query = $this->database->select('esn_membership_manager_applications', 'a');
            $query->fields('a');

            if ($isESNcard) {
                $query->condition('esncard_number', $identifier);
            } elseif ($isPass) {
                $query->condition('pass_token', $identifier);
            }

            $application = $query->execute()->fetchAssoc();

            if (empty($application['date_last_modified'])) {
                $lastModifiedDate = new DateTime($application['date_created']);
            } else {
                $lastModifiedDate = new DateTime($application['date_last_modified']);
            }
        } catch (Exception $e) {
            $this->logger->error('Creation of Apple Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'There was a problem getting the card/pass.', $e);
        }

        if (empty($application)) {
            throw new NotFoundHttpException('No application was provided.', null, 404);
        }

        try {
            if ($isESNcard) {
                $passData = $this->appleWalletService->createESNcard($application);
            } else {
                $passData = $this->appleWalletService->createFreePass($application);
            }
            if (empty($passData)) {
                throw new Exception();
            }
        } catch (Exception $e) {
            $this->logger->error('Creation of Apple Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'Unable to generate your Apple Wallet Pass.');
        }

        $response = new Response($passData);
        $response->setPublic();
        $response->setLastModified($lastModifiedDate);
        $response->headers->set('Content-Type', 'application/vnd.apple.pkpass');
        $response->headers->set('Content-Disposition', 'attachment; filename="esn_membership_manager.pkpass"');

        return $response;
    }

    protected function downloadGuest(string $identifier): Response
    {
        try {
            $query = $this->database->select('esn_membership_manager_guest_passes', 'g');
            $query->addField('g', 'id', 'id');
            $query->addField('g', 'name', 'guest_name');
            $query->addField('g', 'surname', 'guest_surname');
            $query->addField('g', 'date_approved', 'date_approved');
            $query->addField('a', 'name', 'referer_name');
            $query->addField('a', 'surname', 'referer_surname');
            $query->addField('a', 'mobility_status', 'referer_mobility_status');
            $query->condition('g.guest_pass_token', $identifier);
            $query->join('esn_membership_manager_applications', 'a', 'a.id = g.referrer_id');
            $application = $query->execute()->fetchAssoc();

            if (empty($application['date_last_modified'])) {
                $lastModifiedDate = new DateTime($application['date_created']);
            } else {
                $lastModifiedDate = new DateTime($application['date_last_modified']);
            }
        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'There was a problem getting the guest pass.');
        }

        if (empty($application)) {
            throw new NotFoundHttpException('Guest Pass not found.', null, 404);
        }

        try {
            $passData = $this->appleWalletService->createGuestPass($application);
            if (empty($passData)) {
                throw new Exception();
            }
        } catch (Exception $e) {
            $this->logger->error('Creation of Apple Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'Unable to generate your Apple Wallet Pass.');
        }

        $response = new Response($passData);
        $response->setPublic();
        $response->setLastModified($lastModifiedDate);
        $response->headers->set('Content-Type', 'application/vnd.apple.pkpass');
        $response->headers->set('Content-Disposition', 'attachment; filename="esn_membership_manager.pkpass"');
        return $response;
    }

    private function isValidAuthToken(?string $authHeader, string $serialNumber): bool
    {
        if (empty($authHeader) || !str_starts_with($authHeader, 'ApplePass ')) {
            return false;
        }
        $token = substr($authHeader, 10);

        $siteSalt = $this->settings::getHashSalt();
        $expectedToken = hash('sha256', $serialNumber . $siteSalt);

        return $token === $expectedToken;
    }

    public function handleDeviceRegistration(Request $request, string $deviceLibraryIdentifier, string $passTypeIdentifier, string $serialNumber): Response|JSONResponse
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$this->isValidAuthToken($authHeader, $serialNumber)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if ($request->getMethod() === 'POST') {
            $content = json_decode($request->getContent(), TRUE);
            $pushToken = $content['pushToken'] ?? null;

            if (!$pushToken) {
                return new JsonResponse(['error' => 'Missing pushToken'], 400);
            }

            $exists = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                    ->condition('device_library_identifier', $deviceLibraryIdentifier)
                    ->condition('serial_number', $serialNumber)
                    ->countQuery()
                    ->execute()
                    ->fetchField() != 0;

            if ($exists) {
                return new Response('', 200);
            }

            try {
                $this->database->insert('esn_membership_manager_apple_wallet_registrations')
                    ->fields([
                        'device_library_identifier' => $deviceLibraryIdentifier,
                        'pass_type_identifier' => $passTypeIdentifier,
                        'serial_number' => $serialNumber,
                        'push_token' => $pushToken,
                        'date_created' => (new DrupalDateTime())->format('Y-m-d H:i:s'),
                    ])
                    ->execute();
            } catch (Exception $e) {
                $this->logger->error('Unable to save the Apple Wallet Registration for @serial: @error.', ['@serial' => $serialNumber, '@error' => $e->getMessage()]);
                return new JsonResponse(['error' => 'Unable to save the Apple Wallet Registration.'], 500);
            }

            return new Response('', 201);
        }

        if ($request->getMethod() === 'DELETE') {
            try {
                $this->database->delete('esn_membership_manager_apple_wallet_registrations')
                    ->condition('device_library_identifier', $deviceLibraryIdentifier)
                    ->condition('pass_type_identifier', $passTypeIdentifier)
                    ->condition('serial_number', $serialNumber)
                    ->execute();
            } catch (Exception $e) {
                $this->logger->error('Unable to delete the Apple Wallet Registration for @serial: @error.', ['@serial' => $serialNumber, '@error' => $e->getMessage()]);
                return new JsonResponse(['error' => 'Unable to delete the Apple Wallet Registration.'], 500);
            }

            return new Response('', 200);
        }

        return new Response('', 405);
    }

    public function getUpdatablePasses(Request $request, string $deviceLibraryIdentifier, string $passTypeIdentifier): Response
    {
        $passesUpdatedSince = $request->query->get('passesUpdatedSince');

        try {
            $registeredSerialNumbers = $this->database->select('esn_membership_manager_apple_wallet_registrations', 'w')
                ->fields('w', ['serial_number'])
                ->condition('device_library_identifier', $deviceLibraryIdentifier)
                ->condition('pass_type_identifier', $passTypeIdentifier)
                ->execute()
                ->fetchCol();
        } catch (Exception $e) {
            $this->logger->error('Unable to retrieve the device passes for @$device: @error.', ['@device' => $deviceLibraryIdentifier, '@error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Unable to retrieve the device passes.'], 500);
        }

        if (empty($registeredSerialNumbers)) {
            return new Response('', 204);
        }

        $updatedSerialNumbers = [];
        $latestUpdateTime = 0;

        foreach ($registeredSerialNumbers as $serialNumber) {
            $isESNcard = str_starts_with($serialNumber, 'esncard-') == 1;
            $isPass = str_starts_with($serialNumber, 'free_pass-') == 1;
            $isGuest = str_starts_with($serialNumber, 'guest-') == 1;

            if (!$isESNcard && !$isPass && !$isGuest) {
                continue;
            }

            if ($isGuest) {
                $table = 'esn_membership_manager_guest_passes';
                $id = str_replace('guest-', '', $serialNumber);
            } else {
                $table = 'esn_membership_manager_applications';
                $id = $isESNcard ?
                    str_replace('esncard-', '', $serialNumber) :
                    str_replace('free_pass-', '', $serialNumber);

            }

            try {
                $dates = $this->database->select($table, 't')
                    ->fields('t', ['date_created', 'date_last_modified'])
                    ->condition('id', $id)
                    ->execute()
                    ->fetchAssoc();

                if (empty($dates['date_last_modified'])) {
                    $lastModifiedDate = new DateTime($dates['date_created']);
                } else {
                    $lastModifiedDate = new DateTime($dates['date_last_modified']);
                }
            } catch (Exception $e) {
                $this->logger->error('Unable to retrieve the last modified date for @serial: @error.', ['@serial' => $serialNumber, '@error' => $e->getMessage()]);
                continue;
            }

            $lastUpdateEpoch = $lastModifiedDate->getTimestamp();

            if (!$passesUpdatedSince || $lastUpdateEpoch > (int)$passesUpdatedSince) {
                $updatedSerialNumbers[] = (string)$serialNumber;

                if ($lastUpdateEpoch > $latestUpdateTime) {
                    $latestUpdateTime = $lastUpdateEpoch;
                }
            }
        }

        if (empty($updatedSerialNumbers)) {
            return new Response('', 204);
        }

        return new JsonResponse([
            'lastUpdated' => (string)$latestUpdateTime,
            'serialNumbers' => $updatedSerialNumbers
        ]);
    }

    public function getLatestPass(Request $request, string $passTypeIdentifier, string $serialNumber): Response
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$this->isValidAuthToken($authHeader, $serialNumber)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (str_starts_with($serialNumber, 'esncard-') == 1) {
            $type = 'card';
        } elseif (str_starts_with($serialNumber, 'free_pass-') == 1) {
            $type = 'pass';
        } elseif (str_starts_with($serialNumber, 'guest-') == 1) {
            $type = 'guest';
        } else {
            return new JsonResponse(['error' => 'Unexpected serial number structure.'], 400);
        }

        $table = $type == 'guest' ? 'esn_membership_manager_guest_passes' : 'esn_membership_manager_applications';
        $id = match ($type) {
            'card' => str_replace('esncard-', '', $serialNumber),
            'pass' => str_replace('free_pass-', '', $serialNumber),
            'guest' => str_replace('guest-', '', $serialNumber),
        };

        try {
            $query = $this->database->select($table, 't');
            if ($type == 'guest') {
                $query->fields('t', ['guest_pass_token']);
            } else {
                $query->fields('t', ['pass_token', 'esncard_number']);
            }
            $identifiers = $query->condition('id', $id)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Unable to retrieve the application for @serial: @error.', ['@serial' => $serialNumber, '@error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'Unable to retrieve the application.'], 500);
        }

        if (empty($identifiers)) {
            return new JsonResponse(['error' => 'Application not found.'], 404);
        }

        if (empty($identifiers['esncard_number']) && $type == 'card') {
            return new JsonResponse(['error' => 'Application not found.'], 404);
        }

        try {
            return $this->download(
                match ($type) {
                    'card' => $identifiers['esncard_number'],
                    'pass' => $identifiers['pass_token'],
                    'guest' => $identifiers['guest_pass_token'],
                }
            );
        } catch (BadRequestHttpException|HttpException|NotFoundHttpException $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());
        }
    }

    public function logMessage(Request $request): Response
    {
        $content = json_decode($request->getContent(), TRUE);

        if (isset($content['logs']) && is_array($content['logs'])) {
            foreach ($content['logs'] as $message) {
                $this->logger->error('Apple Wallet Device Log: @message', ['@message' => $message]);
            }
        }

        return new Response('', 200);
    }
}