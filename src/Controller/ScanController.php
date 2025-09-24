<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ScanController extends ControllerBase
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

    public function scanCard(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE);
        $card_number = $body['card'] ?? NULL;

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No card number was provided.'], 400);
        }

        $is_esncard = preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) == 1;
        $is_esn_cyprus_pass = preg_match("/ESNCYTKNESNCYTKN\d*/", $card_number) == 1;

        if (!$is_esncard && !$is_esn_cyprus_pass) {
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
            ->condition('webform_id', 'esn_cyprus_pass');

        $orGroup = $query->orConditionGroup()
            ->condition('elements.esncard_number.value', $card_number)
            ->condition('elements.user_token.value', $card_number);

        $query->condition($orGroup);
        $sids = $query->execute();

        if (empty($sids)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 404);
        }

        $submission_id = reset($sids);
        /** @var WebformSubmissionInterface $submission */
        $submission = $storage->load($submission_id);
        $data = $submission->getData();

        $last_scan_date = $data['last_scan_date'] ?? NULL;

        try {
            $submission->setElementData('last_scan_date', (new DrupalDateTime())->format('Y-m-d H:i:s'));
            $submission->save();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unable to update last scan date.'], 500);
        }

        return new JsonResponse([
            'name' => $data['name'],
            'surname' => $data['surname'],
            'nationality' => $data['country_origin'], //TODO Change to Nationality field once available
            'paidDate' => (new DrupalDateTime($data['date_paid']))->format('Y-m-d'),
            'lastScanDate' => (new DrupalDateTime($last_scan_date))->format('Y-m-d'),
            'profileImageURL' => $data['profile_image_esncard'],
        ], 200);
    }
}