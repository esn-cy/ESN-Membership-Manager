<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AddESNcardController extends ControllerBase
{
    public function addESNcard(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $card_number = $body['card'];

        $query = Drupal::database()->select('esncard_numbers', 'e');
        $query->addExpression('MAX(sequence)', 'max_seq');
        try {
            $max_sequence = (int)$query->execute()->fetchField();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem inserting the card.'], 500);
        }

        if (!empty($card_number)) {
            if (preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) == 1) {
                try {
                    Drupal::database()->insert('esncard_numbers')
                        ->fields([
                            'number' => $card_number,
                            'sequence' => $max_sequence + 1,
                            'assigned' => 0,
                        ])
                        ->execute();
                    return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard was added successfully.'], 200);

                } catch (Exception) {
                    return new JsonResponse(['status' => 'error', 'message' => 'There was a problem inserting the card.'], 500);
                }
            } else {
                return new JsonResponse(['status' => 'error', 'message' => 'Invalid ESNcard number was provided.'], 400);
            }
        } else {
            return new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400);
        }
    }
}