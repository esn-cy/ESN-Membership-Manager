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
        $body = json_decode($request->getContent(), TRUE);
        $card_number = $body['card'] ?? null;

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400);
        }

        if (preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) != 1) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid ESNcard number was provided.'], 400);
        }

        $connection = Drupal::database();

        try {
            $query = $connection->select('esncard_numbers', 'e');
            $query->condition('e.number', $card_number);
            $exists = $query->countQuery()->execute()->fetchField() > 0;

            if ($exists) {
                return new JsonResponse(['status' => 'error', 'message' => 'This ESNcard number already exists.'], 409);
            }
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem checking the card.'], 500);
        }

        try {
            $query = $connection->select('esncard_numbers', 'e');
            $query->addExpression('MAX(sequence)', 'max_seq');
            $max_sequence = (int)$query->execute()->fetchField();

            $connection->insert('esncard_numbers')
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
    }
}