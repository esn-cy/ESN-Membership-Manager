<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
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
    /**
     * The entity type manager.
     *
     * @var EntityTypeManagerInterface
     */
    protected $entityTypeManager;

    public function __construct(EntityTypeManagerInterface $entity_type_manager)
    {
        $this->entityTypeManager = $entity_type_manager;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entity_type_manager */
        $entity_type_manager = $container->get('entity_type.manager');

        return new static(
            $entity_type_manager
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

        if (preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) != 1) {
            return new JsonResponse(['status' => 'error', 'message' => 'An invalid card number was provided.'], 400);
        }

        try {
            $storage = $this->entityTypeManager->getStorage('webform_submission');
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Webform module was unavailable.'], 500);
        }

        $query = $storage
            ->getQuery()
            ->accessCheck(FALSE)
            ->condition('webform_id', 'esn_cyprus_pass')
            ->condition('elements.esncard_number.value', $card_number);

        $sids = $query->execute();

        if (empty($sids)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 500);
        }

        $submission_id = reset($sids);
        /** @var WebformSubmissionInterface $submission */
        $submission = $storage->load($submission_id);

        $submission->setElementData('approval_status', $status);
        try {
            $submission->save();
        } catch (EntityStorageException) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the card.'], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard status has been updated.'], 200);
    }
}