<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AddController extends ControllerBase
{
    protected ESNcardService $esncardService;

    public function __construct(ESNcardService $esncardService)
    {
        $this->esncardService = $esncardService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ESNcardService $esncardService */
        $esncardService = $container->get('esn_membership_manager.esncard_service');

        return new static(
            $esncardService,
        );
    }

    public function addCard(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $cardNumber = $body['card'] ?? null;

        $issues = $this->esncardService->addESNcards([$cardNumber]);
        if (empty($issues)) {
            return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard was added successfully.'], 200);
        }

        return match ($issues[0]['issue']) {
            'empty' => new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400),
            'invalid' => new JsonResponse(['status' => 'error', 'message' => 'Invalid ESNcard number was provided.'], 400),
            'duplicate' => new JsonResponse(['status' => 'error', 'message' => 'This ESNcard number already exists.'], 409),
            'database' => new JsonResponse(['status' => 'error', 'message' => 'There was a problem inserting the card.'], 500),
            default => new JsonResponse(['status' => 'error', 'message' => 'Unexpected error occurred.'], 500),
        };
    }
}