<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
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
    protected LoggerChannelInterface $logger;

    public function __construct(
        GoogleService                 $googleService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->googleService = $googleService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $googleService,
            $loggerFactory
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

        try {
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_application');

            if ($isESNcard) {
                $application = $storage->getByESNcard($identifier);
            } elseif ($isPass) {
                $application = $storage->getByPassToken($identifier);
            }
        } catch (Exception $e) {
            $this->logger->error('Creation of Google Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'There was a problem getting the card/pass.');
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
        try {
            /** @var GuestPassStorage $storage */
            $storage = $this->entityTypeManager()->getStorage('membership_guest');

            $guestPass = $storage->getByPassToken($identifier);
            $referrer = $guestPass?->getReferer();
        } catch (Exception $e) {
            $this->logger->error('Adding Guest Pass to Google Wallet failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'There was a problem getting the guest pass.');
        }

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