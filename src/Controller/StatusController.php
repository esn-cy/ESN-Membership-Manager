<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

enum Status: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Declined = 'Declined';
    case Paid = 'Paid';
    case Issued = 'Issued';
    case Delivered = 'Delivered';
}

class StatusController extends ControllerBase
{
    public function changeStatus(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE);
        $card_number = $body['card'];
        $status = $body['status'];

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400);
        }

        if (empty($status)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No status was provided.'], 400);
        }

        if (Status::tryFrom($status) == null) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid status was provided.'], 400);
        }

        if (preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) != 1) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        $webform_id = 'esn_cyprus_pass';

        $query = Drupal::entityQuery('webform_submission')
            ->accessCheck(FALSE)
            ->condition('webform_id', $webform_id);
        $sids = $query->execute();

        if (empty($sids)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Form not found.'], 500);
        }

        $found_submission = null;
        foreach ($sids as $sid) {
            $submission = WebformSubmission::load($sid);
            $data = $submission->getData();
            if (!empty($data['esncard_number']) && $data['esncard_number'] === $card_number) {
                $found_submission = $submission;
                break;
            }
        }

        $found_submission->setElementData('approval_status', $status);
        try {
            $found_submission->save();
        } catch (EntityStorageException) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the card.'], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard status has been updated.'], 200);
    }
}