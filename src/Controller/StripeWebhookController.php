<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    protected StripeService $stripeService;
    protected MarkApplicationAsPaid $markApplicationAsPaid;
    protected ApplicationStorage $applicationStorage;
    protected LoggerChannelInterface $logger;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function __construct(
        StripeService                 $stripeService,
        ActionManager                 $actionManager,
        EntityTypeManagerInterface    $entityTypeManager,
        LoggerChannelFactoryInterface $loggerFactory,
    )
    {
        /** @var MarkApplicationAsPaid $markApplicationAsPaid */
        $markApplicationAsPaid = $actionManager->createInstance('esn_membership_manager_mark_paid');

        /** @var ApplicationStorage $applicationStorage */
        $applicationStorage = $entityTypeManager->getStorage('membership_application');

        $this->stripeService = $stripeService;
        $this->markApplicationAsPaid = $markApplicationAsPaid;
        $this->applicationStorage = $applicationStorage;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var StripeService $stripeService */
        $stripeService = $container->get('esn_membership_manager.stripe_service');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $stripeService,
            $actionManager,
            $entityTypeManager,
            $loggerFactory,
        );
    }

    public function handleWebhook(Request $request): Response
    {
        try {
            $event = $this->stripeService->createApplicationWebhookEvent($request);
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

        if (empty($linkID)) {
            return new Response('Webhook failed: No link ID was present in the event.', 400);
        }

        if (empty($applicationID)) {
            $application = $this->applicationStorage->getByPaymentLinkID($linkID);
        } else {
            $application = $this->applicationStorage->load($applicationID);
        }

        if (empty($application)) {
            $this->logger->warning('Application matching the link was not found for session @session.', ['@session' => $session->id]);
            return new Response('Webhook ignored: Application matching the link was not found', 200);
        }

        try {
            $resultString = $this->markApplicationAsPaid->execute($application, false);
        } catch (Exception $e) {
            return new Response('Webhook processing failed: ' . $e->getMessage(), 500);
        }

        return new Response('Webhook handled: ' . $resultString, 200);
    }
}