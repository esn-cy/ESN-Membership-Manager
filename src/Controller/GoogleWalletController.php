<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Service\GoogleService;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class GoogleWalletController extends ControllerBase
{
    protected GoogleService $googleService;
    protected ApplicationStorage $applicationStorage;
    protected GuestPassStorage $guestPassStorage;
    protected LoggerChannelInterface $logger;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        GoogleService                 $googleService,
        EntityTypeManagerInterface    $entityTypeManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        /** @var GuestPassStorage $guestPassStorage */
        $guestPassStorage = $entityTypeManager->getStorage('membership_guest');

        $this->googleService = $googleService;
        $this->applicationStorage = $applicationStorage;
        $this->guestPassStorage = $guestPassStorage;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $googleService,
            $entityTypeManager,
            $loggerFactory,
        );
    }

    public function addToWallet(string $identifier): Response
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
            return $this->addGuest($identifier);
        }

        if ($isESNcard) {
            $application = $this->applicationStorage->getByESNcard($identifier);
        } elseif ($isPass) {
            $application = $this->applicationStorage->getByPassToken($identifier);
        }

        if (empty($application)) {
            throw new NotFoundHttpException('No application was found.', null, 404);
        }

        try {
            if ($isESNcard) {
                $walletLink = $this->googleService->getESNcardObject($application);
            } else {
                $walletLink = $this->googleService->getFreePassObject($application);
            }
            if (empty($walletLink)) {
                throw new Exception();
            }
        } catch (Exception|GuzzleException $e) {
            $this->logger->error('Creation of Google Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'Unable to generate your Google Wallet Pass.');
        }

        return new TrustedRedirectResponse($walletLink);
    }

    protected function addGuest(string $identifier): TrustedRedirectResponse
    {
        $guestPass = $this->guestPassStorage->getByPassToken($identifier);
        $referrer = $guestPass?->getReferer();

        if (empty($guestPass) || empty($referrer)) {
            throw new NotFoundHttpException('Guest Pass not found.', null, 404);
        }

        try {
            $walletLink = $this->googleService->getGuestPassObject($guestPass, $referrer);
            if (empty($walletLink)) {
                throw new Exception();
            }
        } catch (Exception|GuzzleException $e) {
            $this->logger->error('Creation of Google Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'Unable to generate your Google Wallet Pass.');
        }

        return new TrustedRedirectResponse($walletLink);
    }
}