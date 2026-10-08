<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Controller\StripeWebhookController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Plugin\Action\MarkApplicationAsPaid;
use Drupal\esn_membership_manager\Service\StripeService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use stdClass;
use Stripe\Event;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers \Drupal\esn_membership_manager\Controller\StripeWebhookController
 * @group esn_membership_manager
 */
class StripeWebhookControllerTest extends MembershipManagerTestCase
{
    private StripeService $stripeService;
    private ActionManager $actionManager;
    private MarkApplicationAsPaid $markApplicationAsPaid;
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private LoggerChannelInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeService = $this->createMock(StripeService::class);
        $this->actionManager = $this->createMock(ActionManager::class);
        $this->markApplicationAsPaid = $this->createMock(MarkApplicationAsPaid::class);
        $this->actionManager->method('createInstance')
            ->with('esn_membership_manager_mark_paid')
            ->willReturn($this->markApplicationAsPaid);

        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($this->applicationStorage);

        $this->logger = $this->createMock(LoggerChannelInterface::class);
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $loggerFactory = $this->getLoggerFactoryMock($this->logger);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) use ($loggerFactory) {
                return match ($id) {
                    'esn_membership_manager.stripe_service' => $this->stripeService,
                    'plugin.manager.action' => $this->actionManager,
                    'entity_type.manager' => $this->entityTypeManager,
                    'logger.factory' => $loggerFactory,
                    default => null,
                };
            });

        $controller = StripeWebhookController::create($container);
        $this->assertInstanceOf(StripeWebhookController::class, $controller);
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookEventExceptionReturns400(): void
    {
        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willThrowException(new Exception('Invalid signature'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Unable to construct webhook event: @message', ['@message' => 'Invalid signature']);

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Webhook failed: Unable to construct webhook event', $response->getContent());
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookIgnoredEventTypeReturns200(): void
    {
        $event = new Event();
        $event->type = 'payment_intent.succeeded';

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Webhook ignored: Event not processable', $response->getContent());
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookMissingLinkIdReturns400(): void
    {
        $session = new stdClass();
        $session->metadata = new stdClass();
        $session->metadata->application_id = '42';
        $session->payment_link = null;

        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = new stdClass();
        $event->data->object = $session;

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(400, $response->getStatusCode());
        $this->assertEquals('Webhook failed: No link ID was present in the event.', $response->getContent());
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookApplicationNotFoundReturns200(): void
    {
        $session = new stdClass();
        $session->id = 'cs_test_123';
        $session->metadata = new stdClass();
        $session->metadata->application_id = '999';
        $session->payment_link = 'plink_abc';

        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = new stdClass();
        $event->data->object = $session;

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('999')
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Application matching the link was not found for session @session.', ['@session' => 'cs_test_123']);

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Webhook ignored: Application matching the link was not found', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookSuccessWithApplicationId(): void
    {
        $session = new stdClass();
        $session->metadata = new stdClass();
        $session->metadata->application_id = '42';
        $session->payment_link = 'plink_abc';

        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = new stdClass();
        $event->data->object = $session;

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $application = $this->createMock(ApplicationInterface::class);
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('42')
            ->willReturn($application);

        $this->markApplicationAsPaid->expects($this->once())
            ->method('execute')
            ->with($application, false)
            ->willReturn('Application 42 marked as paid.');

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Webhook handled: Application 42 marked as paid.', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookSuccessWithPaymentLinkIdFallback(): void
    {
        $session = new stdClass();
        $session->metadata = null;
        $session->payment_link = 'plink_abc';

        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = new stdClass();
        $event->data->object = $session;

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $application = $this->createMock(ApplicationInterface::class);
        $this->applicationStorage->expects($this->once())
            ->method('getByPaymentLinkID')
            ->with('plink_abc')
            ->willReturn($application);

        $this->markApplicationAsPaid->expects($this->once())
            ->method('execute')
            ->with($application, false)
            ->willReturn('Application marked as paid via link.');

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Webhook handled: Application marked as paid via link.', $response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginException
     * @throws PluginNotFoundException
     */
    public function testHandleWebhookExecuteExceptionReturns500(): void
    {
        $session = new stdClass();
        $session->metadata = new stdClass();
        $session->metadata->application_id = '42';
        $session->payment_link = 'plink_abc';

        $event = new Event();
        $event->type = 'checkout.session.completed';
        $event->data = new stdClass();
        $event->data->object = $session;

        $this->stripeService->expects($this->once())
            ->method('createApplicationWebhookEvent')
            ->willReturn($event);

        $application = $this->createMock(ApplicationInterface::class);
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with('42')
            ->willReturn($application);

        $this->markApplicationAsPaid->expects($this->once())
            ->method('execute')
            ->with($application, false)
            ->willThrowException(new Exception('Database lock timeout'));

        $controller = new StripeWebhookController(
            $this->stripeService,
            $this->actionManager,
            $this->entityTypeManager,
            $this->getLoggerFactoryMock($this->logger)
        );

        $response = $controller->handleWebhook(new Request());
        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals('Webhook processing failed: Database lock timeout', $response->getContent());
    }
}
