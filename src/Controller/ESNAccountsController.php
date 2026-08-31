<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_accounts_api\Entity\Organisation;
use Drupal\omnia\Config\OmniaSettings;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class ESNAccountsController extends ControllerBase
{
    protected Connection $database;
    protected ClientInterface $httpClient;
    protected EntityStorageInterface $organisationStorage;
    protected OmniaSettings $omniaSettings;
    protected LoggerChannelInterface $logger;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function __construct(
        Connection                    $database,
        ClientInterface               $httpClient,
        EntityTypeManagerInterface    $entityTypeManager,
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        $this->database = $database;
        $this->httpClient = $httpClient;
        $this->organisationStorage = $entityTypeManager->getStorage('esn_organisation');
        $this->omniaSettings = new OmniaSettings($configFactory);
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

        /** @var ClientInterface $httpClient */
        $httpClient = $container->get('http_client');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $httpClient,
            $entityTypeManager,
            $configFactory,
            $loggerFactory,
        );
    }

    public function callback(Request $request): RedirectResponse
    {
        $ticket = $request->query->get('ticket');
        $token = $request->query->get('token');

        if (empty($ticket) || empty($token)) {
            return new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());
        }

        try {
            $application = $this->database->select('esn_membership_manager_in_progress_applications', 'i')
                ->fields('i')
                ->condition('esn_token', $token)
                ->execute()
                ->fetchAssoc();
        } catch (Exception $e) {
            $this->logger->error('ESN Accounts authentication failed. @error', ['@error' => $e->getMessage()]);
            try {
                $this->database->update('esn_membership_manager_in_progress_applications')
                    ->fields(['esn_status' => 'Failed'])
                    ->condition('esn_token', $token)
                    ->execute();
            } catch (Exception $updateException) {
                $this->logger->error('Failed to update application status to Failed. @error', ['@error' => $updateException->getMessage()]);
            }
        }

        if (empty($application)) {
            return new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());
        }

        try {
            $serviceURL = urlencode(Url::fromRoute('esn_membership_manager.apply_verify_esn', [], ['absolute' => true])->toString() . '?token=' . $token);
            $response = $this->httpClient->get("https://accounts.esn.org/cas/serviceValidate?service=$serviceURL&ticket=$ticket");

            $xml = simplexml_load_string($response->getBody()->getContents(), 'SimpleXMLElement', 0, 'cas', TRUE);
            if (isset($xml->authenticationSuccess)) {
                $attributes = $xml->authenticationSuccess->attributes;
                if (empty($attributes->extended_roles)) {
                    $this->database->update('esn_membership_manager_in_progress_applications')
                        ->fields(['esn_status' => 'No Roles'])
                        ->condition('id', $application['id'])
                        ->execute();

                    return new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());
                }

                $sectionMode = $this->omniaSettings->getSectionMode();
                /** @var Organisation $nationalOrganisation */
                $nationalOrganisation = $this->organisationStorage->load($this->omniaSettings->getNationalOrganisationID());

                $sections = [];
                if (!$sectionMode) {
                    /** @var Organisation[] $sectionsByID */
                    $sectionsByID = $this->organisationStorage->loadByProperties(['type' => 'section', 'country_code' => $nationalOrganisation->getCountryCode()]);
                    foreach ($sectionsByID as $section) {
                        $sections[$section->getCode()] = $section;
                    }
                } else {
                    /** @var Organisation $section */
                    $section = $this->organisationStorage->load($this->omniaSettings->getOrganisationID());
                    if (!empty($section)) {
                        $sections[$section->getCode()] = $section;
                    }
                }

                $assignedOrganization = null;
                $assignedRole = null;
                $currentPriority = 0; // Priority values: 4 = Active National, 3 = Active Local, 2 = Alumnus National, 1 = Alumnus Local, 0 = None
                foreach ($attributes->extended_roles as $role) {
                    $roleParts = explode(':', (string)$role);
                    if (count($roleParts) < 2) {
                        continue;
                    }

                    if (!$sectionMode && str_starts_with($roleParts[0], 'National')) {
                        if ($nationalOrganisation->getCountryCode() == $roleParts[1]) {
                            $isAlumnus = ($roleParts[0] === 'National.alumnus');
                            $priority = $isAlumnus ? 2 : 4;

                            if ($priority > $currentPriority) {
                                $assignedOrganization = $nationalOrganisation->getTitle();
                                $assignedRole = $isAlumnus ? 'ESN Alumnus' : 'ESN Volunteer';
                                $currentPriority = $priority;
                            }
                        }
                    } elseif (str_starts_with($roleParts[0], 'Local')) {
                        if (isset($sections[$roleParts[1]])) {
                            $section = $sections[$roleParts[1]];
                            $isAlumnus = ($roleParts[0] === 'Local.alumnus');
                            $priority = $isAlumnus ? 1 : 3;

                            if ($priority > $currentPriority) {
                                $assignedOrganization = $section->getTitle();
                                $assignedRole = $isAlumnus ? 'ESN Alumnus' : 'ESN Volunteer';
                                $currentPriority = $priority;
                            }
                        }
                    }
                }

                if (!empty($assignedOrganization) && !empty($assignedRole)) {
                    $this->database->update('esn_membership_manager_in_progress_applications')
                        ->fields([
                            'status_name' => $attributes->first,
                            'status_surname' => $attributes->last,
                            'status_mobility' => $assignedRole,
                            'status_host_institution' => $assignedOrganization,
                            'esn_status' => 'Success'
                        ])
                        ->condition('id', $application['id'])
                        ->execute();
                } else {
                    $this->database->update('esn_membership_manager_in_progress_applications')
                        ->fields(['esn_status' => 'Foreign Roles'])
                        ->condition('id', $application['id'])
                        ->execute();
                }
                return new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());
            }
            throw new Exception('Failed login.');
        } catch (Exception|GuzzleException $e) {
            $this->logger->error('ESN Accounts authentication failed. @error', ['@error' => $e->getMessage()]);
            try {
                $this->database->update('esn_membership_manager_in_progress_applications')
                    ->fields(['esn_status' => 'Failed'])
                    ->condition('id', $application['id'])
                    ->execute();
            } catch (Exception $updateException) {
                $this->logger->error('Failed to update application status to Failed. @error', ['@error' => $updateException->getMessage()]);
            }

            return new RedirectResponse(Url::fromRoute('esn_membership_manager.apply', [], ['absolute' => true])->toString());
        }
    }
}