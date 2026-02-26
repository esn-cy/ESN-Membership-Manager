<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Action\ActionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
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
    protected $configFactory;
    protected ActionManager $actionManager;
    protected LoggerChannelInterface $logger;
    protected StripeService $stripeService;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        ActionManager                 $actionManager,
        LoggerChannelFactoryInterface $loggerFactory,
        StripeService                 $stripeService
    )
    {
        $this->configFactory = $configFactory;
        $this->actionManager = $actionManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->stripeService = $stripeService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        return new static(
            $configFactory,
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
            return new Response('Webhook failed', 400);
        }

        if ($event->type != 'checkout.session.completed') {
            return new Response('Webhook ignored', 200);
        }

        $session = $event->data->object;
        $applicationID = $session->metadata->application_id ?? NULL;
        $linkID = $session->payment_link ?? NULL;

        if (!$applicationID) {
            $this->logger->warning('No application_id metadata in Stripe session.');
            return new Response('Webhook ignored', 200);
        }

        try {
            if ($this->actionManager->hasDefinition('esn_membership_manager_mark_paid')) {
                /** @var MarkSubmissionAsPaid $action */
                $action = $this->actionManager->createInstance('esn_membership_manager_mark_paid');
                $action->execute($applicationID, $linkID);
            } else {
                $this->logger->error('Mark Submissions as Paid Action plugin not found.');
                return new Response('Webhook processing failed: Mark Submissions as Paid Action plugin not found.', 500);
            }
        } catch (Exception $e) {
            return new Response('Webhook processing failed: ' . $e->getMessage(), 500);
        }

        return new Response('Webhook handled', 200);
    }
}