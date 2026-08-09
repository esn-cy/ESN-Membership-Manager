<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\EmailManager;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AuthenticationController extends ControllerBase
{
    protected Connection $database;
    protected ApplicationStorage $applicationStorage;
    protected EmailManager $emailManager;
    protected LoggerChannelInterface $logger;
    protected array $allowedTypes = ['login', 'register'];

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        Connection                    $database,
        EntityTypeManagerInterface    $entityTypeManager,
        EmailManager                  $emailManager,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        $this->database = $database;
        $this->applicationStorage = $applicationStorage;
        $this->emailManager = $emailManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var EmailManager $emailManager */
        $emailManager = $container->get('esn_membership_manager.email_manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $entityTypeManager,
            $emailManager,
            $loggerFactory,
        );
    }

    public function request(Request $request, string $type): JsonResponse
    {
        if (!in_array($type, $this->allowedTypes)) {
            return new JsonResponse(['error' => 'Invalid authentication type.'], 400);
        }

        $payload = json_decode($request->getContent(), TRUE);
        $email = $payload['email'] ?? NULL;

        if (!$email) {
            return new JsonResponse(['error' => 'Email is required.'], 400);
        }

        switch ($type) {
            case 'login':
                $successMessage = 'A code was sent to your email address.';
                $emailHeader = 'Login';

                $exists = $this->applicationStorage->countByEmail($email) > 0;
                break;
            case 'register':
                $successMessage = 'A code was sent to your email address. If you do not receive it within 5 minutes, please check your spam or try a different email.';
                $emailHeader = 'Registration';
                $exists = TRUE;
                break;
            default:
                return new JsonResponse(['error' => 'Invalid authentication type.'], 400);
        }

        if (!$exists) {
            return new JsonResponse(null, 200);
        }

        try {
            $code = (string)random_int(10000000, 99999999);
        } catch (Exception) {
            $code = substr(str_shuffle("0123456789"), 0, 8);
        }

        $expiresAt = time() + 900;

        try {
            $this->database->merge('esn_membership_manager_authentication')
                ->keys([
                    'email' => $email,
                    'type' => $type,
                ])
                ->fields([
                    'code' => $code,
                    'expires_at' => $expiresAt,
                ])
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Authentication code creation failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['error' => 'There was an issue while processing your request. Please try again later.'], 500);
        }

        $this->emailManager->sendEmail($email, 'authentication', [
            'authentication_type' => $emailHeader,
            'authentication_code' => $code,
        ]);

        return new JsonResponse([
            'status' => 'success',
            'message' => $successMessage
        ], 200);
    }

    public function verify(Request $request, string $type): JsonResponse
    {
        $payload = json_decode($request->getContent(), TRUE);
        $email = $payload['email'] ?? NULL;
        $code = $payload['code'] ?? NULL;

        if (!$email || !$code) {
            return new JsonResponse(['error' => 'Email and code are required.'], 400);
        }

        if (!in_array($type, $this->allowedTypes)) {
            return new JsonResponse(['error' => 'Invalid authentication type.'], 400);
        }

        try {
            $authRecord = $this->database->select('esn_membership_manager_authentication', 'a')
                ->fields('a')
                ->condition('email', $email)
                ->condition('type', $type)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('Authentication code verification failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['error' => 'There was an issue while processing your request. Please try again later.'], 500);
        }

        if (!$authRecord || $authRecord['code'] !== $code || $authRecord['expires_at'] < time()) {
            return new JsonResponse(['error' => 'Invalid or expired code.'], 401);
        }

        try {
            $this->database->delete('esn_membership_manager_authentication')
                ->condition('email', $email)
                ->condition('type', $type)
                ->execute();
        } catch (Exception $e) {
            $this->logger->error('Authentication code verification failed: @message', ['@message' => $e->getMessage()]);
            return new JsonResponse(['error' => 'There was an issue while processing your request. Please try again later.'], 500);
        }

        $session = $request->getSession();
        $session->set($type . '_verified_email', $email);

        return new JsonResponse([], 200);
    }
}