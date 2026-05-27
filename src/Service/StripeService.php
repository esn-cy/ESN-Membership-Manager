<?php

namespace Drupal\esn_membership_manager\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\esn_cyprus_core\Service\StripeServiceBase;
use Drupal\esn_membership_manager\Config\ModuleSettings;
use Exception;
use Stripe\Event;
use Stripe\PaymentLink;
use Symfony\Component\HttpFoundation\Request;

class StripeService extends StripeServiceBase
{
    public function __construct(
        ConfigFactoryInterface        $configFactory,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        parent::__construct($configFactory, $loggerFactory);
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * Create a Stripe payment link for the given application.
     *
     * @param int|string $applicationID The application ID.
     * @param bool $isESNer If the applicant deserves the ESNer price.
     *
     * @return PaymentLink|null The payment link URL or null on failure.
     *
     * @throws Exception
     */
    public function createApplicationPaymentLink(int|string $applicationID, bool $isESNer): ?PaymentLink
    {
        $priceIDs = $this->getPriceIDs($isESNer);

        if (empty($priceIDs['esncard'])) {
            $this->logger->error('Stripe Price ID for ESNcard is not configured.');
            return null;
        }

        $prices = [['price' => $priceIDs['esncard'], 'quantity' => 1]];

        if (!empty($priceIDs['processing'])) {
            $prices[] = ['price' => $priceIDs['processing'], 'quantity' => 1];
        }

        return $this->createPaymentLink($prices, [['application_id' => (string)$applicationID]]);
    }

    /**
     * Creates a webhook event out of a request.
     *
     * @param Request $request The request to be processed.
     *
     * @throws Exception
     */
    public function createApplicationWebhookEvent(Request $request): ?Event
    {
        $moduleSettings = new ModuleSettings($this->configFactory);
        $webhookSecret = $moduleSettings->getStripeWebhookSecret();
        if (empty($webhookSecret)) {
            $this->logger->error('Stripe Webhook Key not set in the module configuration.');
            throw new Exception('Stripe Webhook Key not set in the module configuration.');
        }

        return $this->createWebhookEvent($request, $webhookSecret);
    }

    protected function getPriceIDs(bool $isESNer): array
    {
        $moduleSettings = new ModuleSettings($this->configFactory);

        $esnCardPriceID = $moduleSettings->getESNcardPriceID($isESNer);
        if (empty($esnCardPriceID)) {
            $esnCardPriceID = $moduleSettings->getESNcardPriceID(false);
        }

        $processingFeePriceID = $moduleSettings->getProcessingPriceID($isESNer);
        if (empty($processingFeePriceID)) {
            $processingFeePriceID = $moduleSettings->getProcessingPriceID(false);
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
    public function getPriceAmount(bool $isESNer): ?float
    {
        $priceIDs = $this->getPriceIDs($isESNer);

        $esncardPrice = $this->getPrice($priceIDs['esncard']);
        if (empty($esncardPrice)) {
            return null;
        }

        $totalPrice = $esncardPrice->unit_amount / 100;

        if (!empty($priceIDs['processing'])) {
            $processingPrice = $this->getPrice($priceIDs['processing']);
            if (empty($processingPrice)) {
                return null;
            }

            $totalPrice += $processingPrice->unit_amount / 100;
        }

        return $totalPrice;
    }
}