<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\webform\Entity\WebformSubmission;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScanController
{
    public function scanCard(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $card_number = $body['card'];

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $is_esncard = preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) == 1;
        $is_esn_cyprus_pass = preg_match("/ESNCYTKNESNCYTKN\d*/", $card_number) == 1;

        if (!$is_esncard && !$is_esn_cyprus_pass) {
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
            if($is_esncard) {
                if (!empty($data['esncard_number']) && $data['esncard_number'] === $card_number) {
                    $found_submission = $submission;
                    break;
                }
            } else {
                if (!empty($data['user_token']) && $data['user_token'] === $card_number) {
                    $found_submission = $submission;
                    break;
                }
            }
        }

        if (!$found_submission) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 404);
        }

        $data = $found_submission->getData();

        return new JsonResponse([
            'name' => $data['name'],
            'surname' => $data['surname'],
            'nationality' => $data['country_origin'], //TODO Change to Nationality field once available
            'paidDate' => (new DrupalDateTime($data['date_paid']))->format('Y-m-d'),
            'lastScanDate' => (new DrupalDateTime($data['last_scan_date']))->format('Y-m-d'),
            'profileImageURL' => $data['profile_image_esncard'],
        ], 200);

    }
}