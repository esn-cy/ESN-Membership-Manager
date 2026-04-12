<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Service\WeeztixApiService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class WeeztixAuthController extends ControllerBase
{
    protected WeeztixApiService $apiService;

    public function __construct(WeeztixApiService $api_service)
    {
        $this->apiService = $api_service;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var WeeztixApiService $apiService */
        $apiService = $container->get('esn_membership_manager.weeztix_api_service');

        return new static(
            $apiService
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $code = $request->query->get('code');
        $state = $request->query->get('state');
        $sessionState = $request->getSession()->get('weeztix_oauth_state');

        // Validation
        if (!$code) {
            $this->messenger()->addError($this->t('No authorization code received from Weeztix.'));
            return $this->redirect('esn_membership_manager.settings');
        }

        if ($state !== $sessionState) {
            $this->messenger()->addError($this->t('Invalid state parameter. Possible CSRF attempt.'));
            return $this->redirect('esn_membership_manager.settings');
        }

        $redirectURI = Url::fromRoute('esn_membership_manager.weeztix_oauth_callback', [], ['absolute' => TRUE])->toString();

        $success = $this->apiService->authorizeWithCode($code, $redirectURI);

        if ($success) {
            $this->messenger()->addStatus($this->t('Successfully connected to Weeztix!'));
        } else {
            $this->messenger()->addError($this->t('Failed to exchange code for access token. Check logs.'));
        }

        $request->getSession()->remove('weeztix_oauth_state');

        return $this->redirect('esn_membership_manager.settings');
    }
}