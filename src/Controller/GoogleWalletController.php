<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
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
    protected Connection $database;
    protected LoggerChannelInterface $logger;

    public function __construct(
        GoogleService                 $googleService,
        Connection                    $database,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->googleService = $googleService;
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var GoogleService $googleService */
        $googleService = $container->get('esn_membership_manager.google_service');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $googleService,
            $database,
            $loggerFactory
        );
    }

    public function addToWallet($identifier): Response
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
            $query = $this->database->select('esn_membership_manager_applications', 'a');
            $query->fields('a');

            if ($isESNcard) {
                $query->condition('esncard_number', $identifier);
            } elseif ($isPass) {
                $query->condition('pass_token', $identifier);
            }

            $application = $query->execute()->fetchAssoc();
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
        } catch (Exception $e) {
            $this->logger->error('Scan query failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'There was a problem getting the guest pass.');
        }

        if (empty($application)) {
            throw new NotFoundHttpException('Guest Pass not found.', null, 404);
        }

        try {
            $walletLink = $this->googleService->getGuestPassObject($application);
            if (empty($walletLink)) {
                throw new Exception();
            }
        } catch (Exception $e) {
            $this->logger->error('Creation of Google Wallet Pass failed: @message', ['@message' => $e->getMessage()]);
            throw new HttpException(500, 'Unable to generate your Google Wallet Pass.');
        }

        return new TrustedRedirectResponse($walletLink);
    }
}