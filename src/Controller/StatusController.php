<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class StatusController extends ControllerBase
{
    protected ActionManager $actionManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ActionManager                 $actionManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->actionManager = $actionManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $actionManager,
            $loggerFactory
        );
    }

    protected array $statuses = [
        [
            'name' => 'Approved',
            'action' => 'esn_membership_manager_approve',
            'passAllowed' => TRUE,
            'cardAllowed' => TRUE
        ],
        [
            'name' => 'Rejected',
            'action' => 'esn_membership_manager_reject',
            'passAllowed' => TRUE,
            'cardAllowed' => TRUE
        ],
        [
            'name' => 'Paid',
            'action' => 'esn_membership_manager_mark_paid',
            'passAllowed' => TRUE,
            'cardAllowed' => TRUE
        ],
        [
            'name' => 'Issued',
            'action' => 'esn_membership_manager_issue',
            'passAllowed' => FALSE,
            'cardAllowed' => TRUE
        ],
        [
            'name' => 'Delivered',
            'action' => 'esn_membership_manager_deliver',
            'passAllowed' => FALSE,
            'cardAllowed' => TRUE
        ],
        [
            'name' => 'Blacklisted',
            'action' => 'esn_membership_manager_blacklist',
            'passAllowed' => TRUE,
            'cardAllowed' => FALSE
        ]
    ];

    public function changeStatus(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $identifier = trim($body['card'] ?? '');
        $applicationID = trim($body['id'] ?? '');
        $status = trim($body['status'] ?? '');

        if ((empty($identifier) && empty($applicationID)) || (empty($status))) {
            return new JsonResponse(['status' => 'error', 'message' => 'The request was missing required parameters.'], 400);
        }

        preg_match('/^([a-zA-Z]+)/', $status, $matches);
        $baseStatus = $matches[1] ?? '';

        if ($baseStatus == 'Rejected') {
            $rejectedRegex = '/^Rejected(?:-[a-zA-Z]+-[a-zA-Z]+(?:\/Rejected-[a-zA-Z]+-[a-zA-Z]+)*)?$/';

            if (!preg_match($rejectedRegex, $status)) {
                $this->logger->warning('Rejected malformed rejection status string: @status', ['@status' => $status]);
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid rejection reason format.'], 400);
            }
        } else {
            if ($status != $baseStatus) {
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid status format provided.'], 400);
            }
        }

        $selectedAction = array_filter($this->statuses, function ($search) use ($baseStatus) {
            return $search['name'] == $baseStatus;
        });

        $selectedAction = reset($selectedAction);

        if (empty($selectedAction)) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid status was provided.'], 400);
        }

        if (empty($applicationID)) {
            $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $identifier) == 1;
            $isPass = preg_match("/^[A-F0-9]{32}$/", $identifier) == 1;

            if (!$isESNcard && !$isPass) {
                return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
            }

            if (($isESNcard && !$selectedAction['cardAllowed']) || ($isPass && !$selectedAction['passAllowed'])) {
                return new JsonResponse(['status' => 'error', 'message' => 'Action not allowed with this kind of identifier.'], 400);
            }

            try {
                /** @var ApplicationStorage $storage */
                $storage = $this->entityTypeManager()->getStorage('membership_application');

                if ($isESNcard) {
                    $application = $storage->getByESNcard($identifier);
                } elseif ($isPass) {
                    $application = $storage->getByPassToken($identifier);
                }
            } catch (Exception) {
                return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
            }
        } else {
            if (!is_numeric($applicationID)) {
                return new JsonResponse(['status' => 'error', 'message' => 'An invalid ID was provided.'], 400);
            }

            try {
                /** @var ApplicationStorage $storage */
                $storage = $this->entityTypeManager()->getStorage('membership_application');

                $application = $storage->load($applicationID);
            } catch (Exception) {
                return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
            }
        }

        if (empty($application)) {
            $this->logger->warning('Application @id was not found', ['@id' => $applicationID]);
            return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
        }

        $hasESNcard = $application->getValue(ApplicationField::HasESNcard);

        if (($hasESNcard && !$selectedAction['cardAllowed']) || (!$hasESNcard && !$selectedAction['passAllowed'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Action not allowed for this application.'], 400);
        }

        try {
            if ($this->actionManager->hasDefinition($selectedAction['action'])) {
                /** @var ActionBase $action */
                $action = $this->actionManager->createInstance($selectedAction['action']);

                $access = $action->access(NULL, $this->currentUser(), TRUE);
                if (!$access || !$access->isAllowed()) {
                    return new JsonResponse([
                        'status' => 'error',
                        'message' => 'You do not have permission to perform this action.'
                    ], 403);
                }

                if ($baseStatus == 'Rejected') {
                    $action->execute($application, $status);
                } else {
                    $action->execute($application);
                }

                $this->logger->info('Successfully changed the status of Application @id to @action.', [
                    '@id' => $application->id(),
                    '@action' => $selectedAction['name'],
                ]);
            } else {
                return new JsonResponse(['status' => 'error', 'message' => 'Action plugin not found.'], 500);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to change status of Application @id to @action: @message', [
                '@id' => $application->id(),
                '@action' => $selectedAction['name'],
                '@message' => $e->getMessage()
            ]);
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The status of the application has been updated.'], 200);
    }
}