<?php

namespace Drupal\esn_cyprus_pass_validation\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;
use Stripe\Stripe;
use Stripe\PaymentLink;
use Exception;

/**
 * Approves a webform submission and creates a Stripe payment link.
 *
 * @Action(
 *   id = "esn_cyprus_pass_validation_approve",
 *   label = @Translation("Approve submissions and generate Stripe payment link"),
 *   type = "webform_submission",
 *   confirm = TRUE
 * )
 */
class ApproveSubmission extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
	  if ($entity instanceof WebformSubmissionInterface) {

            // Load submission data.
    $data = $entity->getData();

    // Check if ESNcard option was selected.
    if (!empty($data['choices']) && in_array('ESNcard', $data['choices'])) {
      // Query DB for available ESNcards.
      $connection = \Drupal::database();
      $query = $connection->select('esncard_numbers', 'e');
      $query->addExpression('COUNT(*)', 'count');
      $query->condition('assigned', 0);
      $count = $query->execute()->fetchField();

      if ($count == 0) {
        // No ESNcards available → block approval.
        \Drupal::logger('esn_cyprus_pass_validation')->warning(
          'Submission @id requested ESNcard but none are available.',
          ['@id' => $entity->id()]
        );
        return; // Exit without approving or creating payment link.
      }
    
           

         // Load Stripe secret key.
      
	    $stripeSecretKey = \Drupal::service('settings')->get('stripe.settings')['secret_key'];

      if (empty($stripeSecretKey)) {
        \Drupal::logger('esn_cyprus_pass_validation')->error('Stripe secret key not set in settings.php.');
        return;
      }

      // Initialize Stripe client.
      Stripe::setApiKey($stripeSecretKey);

      try {
        // Create the payment link on Stripe.
        $paymentLink = $this->createStripePaymentLink($entity);

        if ($paymentLink) {
          // Save payment link URL in the submission field 'payment_link'.
          $entity->setElementData('payment_link', $paymentLink);

          \Drupal::logger('esn_cyprus_pass_validation')->notice('Approved submission @id and created payment link.', ['@id' => $entity->id()]);
        }
        else {
          \Drupal::logger('esn_cyprus_pass_validation')->error('Failed to create payment link for submission @id.', ['@id' => $entity->id()]);
        }
      }
      catch (Exception $e) {
        \Drupal::logger('esn_cyprus_pass_validation')->error('Stripe API error for submission @id: @message', ['@id' => $entity->id(), '@message' => $e->getMessage()]);
      }
    }
}
   // Mark submission as approved.
	    $entity->setElementData('approval_status', 'Approved');
	    $entity->setElementData('pass_is_enabled', 1);

          $entity->save();

  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    $result = $account->hasPermission('access custom form');
    return $return_as_object ? $result : $result;
  }

  /**
   * Create a Stripe payment link for the given submission.
   *
   * @param \Drupal\webform\WebformSubmissionInterface $entity
   *   The webform submission.
   *
   * @return string|null
   *   The payment link URL or null on failure.
   */
  protected function createStripePaymentLink(WebformSubmissionInterface $entity) {
    // Customize your price or product info here.
    // Example: fixed product price $20.00 USD

    $unitAmount = 1600; // in cents
    $currency = 'eur';

    // Create a Price object on Stripe (you may want to create once and reuse)
    $price = \Stripe\Price::create([
      'unit_amount' => $unitAmount,
      'currency' => $currency,
      'product_data' => [
        'name' => 'ESNcard',
      ],
    ]);


    $webform_id = $entity->getWebform()->id();

$url = \Drupal::service('url_generator')->generateFromRoute(
  'entity.webform_submission.canonical',
  [
    'webform' => $webform_id,
    'webform_submission' => $entity->id(),
  ],
  ['absolute' => TRUE]
);


    // Create the payment link with the price
    $paymentLink = PaymentLink::create([
      'line_items' => [
        [
          'price' => $price->id,
          'quantity' => 1,
        ],
      ],
      // Optional: add metadata to link payment to user or submission.
      'metadata' => [
        'webform_submission_id' => $entity->id(),
      ],
      // Optional: customize success and cancel URLs
/*      'after_completion' => [
  'type' => 'redirect',
  'redirect' => [
    'url' => $url,
  
  ],],*/
    ]);

    return $paymentLink->url ?? null;
  }

}

