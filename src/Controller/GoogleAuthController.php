<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Config\MembershipSettings;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\omnia\Controller\GoogleAuthControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class GoogleAuthController extends GoogleAuthControllerBase
{
    protected ApplicationStorage $applicationStorage;
    protected Connection $database;
    protected MembershipSettings $membershipSettings;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        Connection                    $database,
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        parent::__construct($loggerFactory);

        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        $this->applicationStorage = $applicationStorage;
        $this->database = $database;
        $this->membershipSettings = new MembershipSettings($configFactory);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entityTypeManager,
            $database,
            $configFactory,
            $loggerFactory,
        );
    }

    protected function getClientID(): string
    {
        return $this->membershipSettings->getGoogleAuthClientID() ?? '';
    }

    protected function getClientSecret(): string
    {
        return $this->membershipSettings->getGoogleAuthClientSecret() ?? '';
    }

    protected function getRedirectURI(Request $request): string
    {
        return Url::fromRoute('esn_membership_manager.google_auth', [], ['absolute' => TRUE])->toString();
    }

    protected function onGoogleAuthSuccess(string|TranslatableMarkup $email, array $payload, ?array $stateData, Request $request): RedirectResponse
    {
        $type = $stateData['type'] ?? 'login';
        $session = $request->getSession();
        $emailExists = $this->applicationStorage->countByEmail($email) > 0;

        switch ($type) {
            case 'login':
                if (!$emailExists) {
                    $this->messenger()->addError($this->t('No application was found for this Google account. Please apply first.'));
                    return $this->redirect('esn_membership_manager.apply');
                }
                $session->set('login_verified_email', $email);
                $session->set('login_email_authentication_data', ['auth_success' => true, 'email' => $email]);
                return $this->redirect('esn_membership_manager.dashboard');
            case 'register':
                if ($emailExists) {
                    $this->messenger()->addError($this->t('An application with this email already exists. Please log in instead.'));
                    return $this->redirect('esn_membership_manager.dashboard');
                }

                $this->database->merge('esn_membership_manager_in_progress_applications')
                    ->key('email', $email)
                    ->fields(['date_created' => (new DrupalDateTime())->format('Y-m-d\TH:i:s')])
                    ->execute();

                $session->set('register_verified_email', $email);
                $session->set('register_email_authentication_data', ['auth_success' => true, 'email' => $email]);

                $savedData = $session->get('application_form_saved_data', []);
                $savedData['email'] = $email;
                $session->set('application_form_saved_data', $savedData);

                $this->messenger()->addStatus($this->t('Successfully authenticated with Google.'));
                return $this->redirect('esn_membership_manager.apply');
        }

        $this->messenger()->addError($this->t('Something went wrong. Please try again later.'));
        return $this->redirect('esn_membership_manager.about');
    }

    protected function onGoogleAuthFailure(string|TranslatableMarkup $errorMessage, ?array $stateData, Request $request): RedirectResponse
    {
        $this->messenger()->addError($errorMessage);
        $type = $stateData['type'] ?? 'login';
        return $this->redirect($type === 'register' ? 'esn_membership_manager.apply' : 'esn_membership_manager.dashboard');
    }
}