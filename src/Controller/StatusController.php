<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\webform\WebformSubmissionInterface;
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
    protected $configFactory;
    protected $entityTypeManager;
    protected Connection $database;

    public function __construct(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entity_type_manager, Connection $database)
    {
        $this->configFactory = $configFactory;
        $this->entityTypeManager = $entity_type_manager;
        $this->database = $database;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var EntityTypeManagerInterface $entity_type_manager */
        $entity_type_manager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        return new static(
            $configFactory,
            $entity_type_manager,
            $database
        );
    }

    public function changeStatus(Request $request): JsonResponse
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

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

        try {
            $query = $this->database->select('webform_submission', 'ws');
            $query->join('webform_submission_data', 'wsd', 'ws.sid = wsd.sid');
            $query->fields('ws', ['sid']);
            $query->condition('ws.webform_id', $moduleConfig->get('webform_id'));
            $query->condition('wsd.name', 'esncard_number');
            $query->condition('wsd.value', $card_number);

            $query->range(0, 1);
            $sid = $query->execute()->fetchField();
        } catch (Exception) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem getting the card.'], 500);
        }

        if (empty($sid)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Card not found.'], 500);
        }

        /** @var WebformSubmissionInterface $submission */
        $submission = $storage->load($sid);

        $submission->setElementData('approval_status', $status);
        try {
            $submission->save();
        } catch (EntityStorageException) {
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem updating the card.'], 500);
        }
        return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard status has been updated.'], 200);
    }
}