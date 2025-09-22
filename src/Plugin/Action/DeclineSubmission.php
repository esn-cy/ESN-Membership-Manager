<?php

namespace Drupal\esn_cyprus_pass_validation\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\webform\WebformSubmissionInterface;

/**
 * Declines a webform submission.
 *
 * @Action(
 *   id = "esn_cyprus_pass_validation_decline",
 *   label = @Translation("Decline submissions"),
 *   type = "webform_submission",
 *   confirm = TRUE
 * )
 */
class DeclineSubmission extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($entity = NULL) {
    if ($entity instanceof WebformSubmissionInterface) {
      $entity->setElementData('approval_status', 'Declined');
      $entity->save();
      \Drupal::logger('esn_cyprus_pass_validation')->notice('Declined submission @id', ['@id' => $entity->id()]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    // Allow only users with 'administer webform submissions' permission.
    $result = $account->hasPermission('access custom form');
    return $return_as_object ? $result : $result;
  }

}
