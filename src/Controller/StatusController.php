<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    protected Connection $database;

    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        return new static(
            $database
        );
    }

    public function changeStatus(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $card_number = $body['card'] ?? null;
        $status = $body['status'] ?? null;

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400);
        }

        if (empty($status)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No status was provided.'], 400);
        }

        if (Status::tryFrom($status) == null) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid status was provided.'], 400);
        }

        if (preg_match("/^\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]$/", $card_number) != 1) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        try {
            $id = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['id'])
                ->condition('esncard_number', $card_number)
                ->execute()
                ->fetchField();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
        }

        if (empty($id)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 404);
        }

        try {
            $this->database->update('esn_membership_manager_applications')
                ->fields([
                    'approval_status' => $status,
                ])
                ->condition('id', $id)
                ->execute();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the card.'], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard status has been updated.'], 200);
    }
}