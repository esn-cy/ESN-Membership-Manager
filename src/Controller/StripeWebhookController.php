<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Plugin\Action\MarkSubmissionAsPaid;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends ControllerBase
{
    protected ActionManager $actionManager;
    protected Connection $database;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        ActionManager                 $actionManager,
        Connection $database,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        $this->actionManager = $actionManager;
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $actionManager,
            $database,
            $loggerFactory,
            $stripeService
        );
    }

    public function handleWebhook(Request $request): Response
    {
        try {
            $event = $this->stripeService->createWebhookEvent($request);
        } catch (Exception $e) {
            $this->logger->error('Unable to construct webhook event: @message', ['@message' => $e->getMessage()]);
            return new Response('Webhook failed: Unable to construct webhook event', 400);
        }

        if ($event->type != 'checkout.session.completed') {
            return new Response('Webhook ignored: Event not processable', 200);
        }

        $session = $event->data->object;
        $applicationID = $session->metadata->application_id ?? NULL;
        $linkID = $session->payment_link ?? NULL;

        if (empty($applicationID) && !empty($linkID)) {
            try {
                $applicationID = $this->database->select('esn_membership_manager_applications', 'a')
                    ->fields('a', ['id'])
                    ->condition('payment_link_id', $linkID)
                    ->range(0, 1)
                    ->execute()
                    ->fetchField();
            } catch (Exception $e) {
                $this->logger->error('Failed to look up application: ' . $e->getMessage());
                $applicationID = NULL;
            }
        }

        if (empty($applicationID)) {
            $this->logger->warning('Application matching the link was not found for session @session.', ['@session' => $session->id]);
            return new Response('Webhook ignored: Application matching the link was not found', 200);
        }

        try {
            if ($this->actionManager->hasDefinition('esn_membership_manager_mark_paid')) {
                /** @var MarkSubmissionAsPaid $action */
                $action = $this->actionManager->createInstance('esn_membership_manager_mark_paid');
                $resultString = $action->execute($applicationID, $linkID);
            } else {
                $this->logger->error('Mark Submissions as Paid Action plugin not found.');
                return new Response('Webhook processing failed: Mark Submissions as Paid Action plugin not found.', 500);
            }
        } catch (Exception $e) {
            return new Response('Webhook processing failed: ' . $e->getMessage(), 500);
        }

        return new Response('Webhook handled: ' . $resultString, 200);
    }
}