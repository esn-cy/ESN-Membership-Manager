<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentLink;
use Stripe\StripeClient;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;

class StripeService
{
    protected ConfigFactoryInterface $configFactory;
    protected LoggerChannelInterface $logger;
    protected ?StripeClient $client = NULL;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * Create a Stripe payment link for the given submission.
     *
     * @param int $id The application ID.
     * @param bool $isESNer If the applicant deserves the ESNer price.
     *
     * @return PaymentLink|null
     *   The payment link URL or null on failure.
     * @throws ApiErrorException
     * @throws Exception
     */
    public function createPaymentLink(int $id, bool $isESNer): ?PaymentLink
    {
        if (!$this->getClient())
            throw new Exception('Stripe Secret Key not set in the module configuration.');

        $priceIDs = $this->getPriceIDs($isESNer);

        if (empty($priceIDs['esncard'])) {
            $this->logger->error('Stripe Price ID for ESNcard is not configured.');
            return null;
        }

        $prices = [['price' => $priceIDs['esncard'], 'quantity' => 1]];

        if (!empty($priceIDs['processing'])) {
            $prices[] = ['price' => $priceIDs['processing'], 'quantity' => 1];
        }

        $paymentLink = $this->client->paymentLinks->create([
            'line_items' => $prices,
            'metadata' => ['application_id' => (string)$id]
        ]);

        return $paymentLink ?? null;
    }

    protected function getClient(): ?StripeClient
    {
        if ($this->client) {
            return $this->client;
        }

        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $stripeSecretKey = $moduleConfig->get('stripe_secret_key');
        if (empty($stripeSecretKey)) {
            $this->logger->error('Stripe Secret Key not set in the module configuration.');
            return NULL;
        }

        $client = new StripeClient($stripeSecretKey);
        $this->client = $client;
        return $client;
    }

    /**
     * Disables a Stripe payment link.
     *
     * @param string $linkID The ID of the link to be disabled.
     *
     * @throws Exception
     */
    public function disablePaymentLink(string $linkID): void
    {
        if (!$this->getClient())
            throw new Exception('Stripe Secret Key not set in the module configuration.');

        $this->client->paymentLinks->update(
            $linkID,
            ['active' => false]
        );
    }

    /**
     * Creates a webhook event out of a request.
     *
     * @param Request $request The request to be processed.
     *
     * @throws Exception
     */
    public function createWebhookEvent(Request $request): Event
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');
        $stripeWebhookSecret = $moduleConfig->get('stripe_webhook_secret');
        if (empty($stripeWebhookSecret)) {
            $this->logger->error('Stripe Webhook Key not set in the module configuration.');
            throw new Exception('Stripe Webhook Key not set in the module configuration.');
        }

        $payload = $request->getContent();
        $signatureHeader = $request->headers->get('Stripe-Signature');

        try {
            return Webhook::constructEvent($payload, $signatureHeader, $stripeWebhookSecret);
        } catch (Exception $e) {
            $this->logger->error('Unable to construct webhook event: @message', ['@message' => $e->getMessage()]);
            throw new Exception('Unable to construct webhook event.');
        }
    }

    protected function getPriceIDs(bool $isESNer): array
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

        if (!$isESNer) {
            $esnCardPriceID = $moduleConfig->get('stripe_price_id_esncard');
            $processingFeePriceID = $moduleConfig->get('stripe_price_id_processing');
        } else {
            $esnCardPriceID = $moduleConfig->get('stripe_price_id_esncard_esner');
            $processingFeePriceID = $moduleConfig->get('stripe_price_id_processing_esner');

            if (empty($esnCardPriceID)) {
                $esnCardPriceID = $moduleConfig->get('stripe_price_id_esncard');
            }

            if (empty($processingFeePriceID)) {
                $processingFeePriceID = $moduleConfig->get('stripe_price_id_processing');
            }
        }

        return ['esncard' => $esnCardPriceID, 'processing' => $processingFeePriceID];
    }

    /**
     * Gets price amount from a given price ID.
     *
     * @param bool $isESNer If the applicant deserves the ESNer price.
     *
     * @throws Exception
     */
    public function getPriceAmount(bool $isESNer): float
    {
        if (!$this->getClient())
            throw new Exception('Stripe Secret Key not set in the module configuration.');

        $priceIDs = $this->getPriceIDs($isESNer);

        $esncardPrice = $this->client->prices->retrieve($priceIDs['esncard']);

        $totalPrice = $esncardPrice->unit_amount / 100;

        if (!empty($priceIDs['processing'])) {
            $processingPrice = $this->client->prices->retrieve($priceIDs['processing']);

            $totalPrice += $processingPrice->unit_amount / 100;
        }

        return $totalPrice;
    }
}