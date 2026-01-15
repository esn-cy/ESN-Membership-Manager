<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class StatusController extends ControllerBase
{
    protected Connection $database;
    protected ActionManager $actionManager;
    protected $currentUser;
    protected LoggerChannelInterface $logger;

    public function __construct(
        Connection                    $database,
        ActionManager                 $actionManager,
        AccountProxyInterface $currentUser,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->database = $database;
        $this->actionManager = $actionManager;
        $this->currentUser = $currentUser;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var AccountProxyInterface $currentUser */
        $currentUser = $container->get('current_user');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $database,
            $actionManager,
            $currentUser,
            $loggerFactory
        );
    }

    protected array $statuses = [
        [
            'name' => 'Paid',
            'action' => 'esn_membership_manager_mark_paid',
            'passAllowed' => TRUE,
            'cardAllowed' => FALSE,
            'bothAllowed' => TRUE
        ],
        [
            'name' => 'Issued',
            'action' => 'esn_membership_manager_issue',
            'passAllowed' => FALSE,
            'cardAllowed' => TRUE,
            'bothAllowed' => TRUE
        ],
        [
            'name' => 'Delivered',
            'action' => 'esn_membership_manager_deliver',
            'passAllowed' => FALSE,
            'cardAllowed' => TRUE,
            'bothAllowed' => TRUE
        ],
        [
            'name' => 'Blacklisted',
            'action' => 'esn_membership_manager_blacklist',
            'passAllowed' => TRUE,
            'cardAllowed' => FALSE,
            'bothAllowed' => FALSE
        ]
    ];

    public function changeStatus(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $cardNumber = $body['card'] ?? null;
        $status = $body['status'] ?? null;

        $isESNcard = preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $cardNumber) == 1;
        $isPass = preg_match("/^[A-F0-9]{32}$/", $cardNumber) == 1;

        if (!$isESNcard && !$isPass) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        $selectedAction = array_filter($this->statuses, function ($search) use ($status) {
            return $search['name'] == $status;
        });

        $selectedAction = reset($selectedAction);

        if (!$selectedAction) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid status was provided.'], 400);
        }

        if (($isESNcard && !$selectedAction['cardAllowed']) || ($isPass && !$selectedAction['passAllowed'])) {
            return new JsonResponse(['status' => 'error', 'message' => 'Action not allowed with this kind of identifier.'], 400);
        }

        try {
            $query = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['id', 'pass', 'esncard']);
            if ($isESNcard)
                $query->condition('esncard_number', $cardNumber);
            else
                $query->condition('pass_token', $cardNumber);
            $data = $query->execute()->fetchAssoc();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
        }

        if (empty($data)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Application not found.'], 404);
        }

        if ((($isESNcard && $data['pass']) || $isPass && $data['esncard']) && !$selectedAction['bothAllowed']) {
            return new JsonResponse(['status' => 'error', 'message' => 'Action not allowed for this application.'], 400);
        }

        try {
            if ($this->actionManager->hasDefinition($selectedAction['action'])) {
                /** @var ActionBase $action */
                $action = $this->actionManager->createInstance($selectedAction['action']);

                $access = $action->access(NULL, $this->currentUser, TRUE);
                if (!$access || !$access->isAllowed()) {
                    return new JsonResponse([
                        'status' => 'error',
                        'message' => 'You do not have permission to perform this action.'
                    ], 403);
                }

                $action->execute($data['id']);

                $this->logger->info('Successfully changed the status of Application @id to @action.', [
                    '@id' => $data['id'],
                    '@action' => $selectedAction['name'],
                ]);
            } else {
                return new JsonResponse(['status' => 'error', 'message' => 'Action plugin not found.'], 500);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to change status of Application @id to @action: @message', [
                '@id' => $data['id'],
                '@action' => $selectedAction['name'],
                '@message' => $e->getMessage()
            ]);
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The status of the application has been updated.'], 200);
    }
}