<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Plugin\Action\MarkApplicationAsPaid;
use Drupal\esn_membership_manager\Service\StripeService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StripeWebhookController extends ControllerBase
{
    protected ActionManager $actionManager;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        ActionManager                 $actionManager,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        $this->actionManager = $actionManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $actionManager,
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
                /** @var ApplicationStorage $storage */
                $storage = $this->entityTypeManager()->getStorage('membership_application');

                $application = $storage->getByPaymentLinkID($linkID);
            } catch (Exception $e) {
                $this->logger->error('Failed to look up application: ' . $e->getMessage());
                $application = NULL;
            }
        }

        if (empty($application)) {
            $this->logger->warning('Application matching the link was not found for session @session.', ['@session' => $session->id]);
            return new Response('Webhook ignored: Application matching the link was not found', 200);
        }

        try {
            if ($this->actionManager->hasDefinition('esn_membership_manager_mark_paid')) {
                /** @var MarkApplicationAsPaid $action */
                $action = $this->actionManager->createInstance('esn_membership_manager_mark_paid');
                $resultString = $action->execute($application, false);
            } else {
                $this->logger->error('Mark Application as Paid Action plugin not found.');
                return new Response('Webhook processing failed: Mark Application as Paid Action plugin not found.', 500);
            }
        } catch (Exception $e) {
            return new Response('Webhook processing failed: ' . $e->getMessage(), 500);
        }

        return new Response('Webhook handled: ' . $resultString, 200);
    }
}