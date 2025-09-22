<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWebhookController extends ControllerBase {

  public function handleWebhook(Request $request) {
    $payload = $request->getContent();
    $sig_header = $request->headers->get('Stripe-Signature');
    
    // Get your webhook secret from config or settings.php
    $endpoint_secret = \Drupal::config('stripe.settings')->get('webhook_secret');
    
    Stripe::setApiKey(\Drupal::config('stripe.settings')->get('secret_key'));
    
    try {
      $event = Webhook::constructEvent($payload, $sig_header, $endpoint_secret);
      
      if ($event->type === 'checkout.session.completed') {
        $session = $event->data->object;
        $submission_id = $session->metadata->webform_submission_id ?? NULL;

        if ($submission_id) {
          $submission = WebformSubmission::load($submission_id);
          if ($submission) {
            // Mark submission as Paid.
            $submission->setElementData('approval_status', 'Paid');
            

            // Assign next available ESNcard number.
            $this->assign_esncard_number($submission);
            //$submission->save();

            \Drupal::logger('esn_cyprus_pass_validation')->notice('Submission @id marked as Paid and assigned ESNcard number.', ['@id' => $submission_id]);
          }
          else {
            \Drupal::logger('esn_cyprus_pass_validation')->warning('Submission ID @id from Stripe webhook not found.', ['@id' => $submission_id]);
          }
        }
        else {
          \Drupal::logger('esn_cyprus_pass_validation')->warning('No webform_submission_id metadata in Stripe session.');
        }
      }
      
      return new Response('Webhook handled', 200);
    }
    catch (\Exception $e) {
      \Drupal::logger('esn_cyprus_pass_validation')->error('Stripe webhook error: @message', ['@message' => $e->getMessage()]);
      return new Response('Webhook error', 400);
    }
  }

  /**
   * Assigns the next available ESNcard number to a submission.
   */
  private function assign_esncard_number(WebformSubmissionInterface $submission) {
    $database = \Drupal::database();

    // Fetch the next available ESNcard number by sequence.
    $next_number = $database->select('esncard_numbers', 'e')
      ->fields('e', ['number'])
      ->condition('assigned', 0)
      ->orderBy('sequence', 'ASC') // Make sure you have a sequence column in your table
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($next_number) {
      // Assign ESNcard number to the submission.
      $submission->setElementData('esncard_number', $next_number);
     $submission->save(); 

      // Mark the number as used in the table.
      $database->update('esncard_numbers')
        ->fields(['assigned' => 1])
        ->condition('number', $next_number)
        ->execute();

      \Drupal::logger('esn_cyprus_pass_validation')->notice('Assigned ESNcard number @num to submission @id.', [
        '@num' => $next_number,
        '@id' => $submission->id(),
      ]);
    }
    else {
      \Drupal::logger('esn_cyprus_pass_validation')->warning('No available ESNcard numbers left to assign.');
    }
  }

}

