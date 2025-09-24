<?php

namespace Drupal\esn_cyprus_pass_validation\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AddController extends ControllerBase
{
    protected Connection $database;
    protected LoggerChannelInterface $logger;


    public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory)
    {
        $this->database = $database;
        $this->logger = $logger_factory->get('esn_cyprus_pass_validation');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $logger */
        $logger = $container->get('logger.factory');

        return new static(
            $database,
            $logger
        );
    }

    public function addCard(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent(), TRUE) ?? [];
        $card_number = $body['card'] ?? null;

        if (empty($card_number)) {
            return new JsonResponse(['status' => 'error', 'message' => 'No ESNcard number was provided.'], 400);
        }

        if (preg_match("/\d\d\d\d\d\d\d[A-Z][A-Z][A-Z][A-Z0-9]/", $card_number) != 1) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid ESNcard number was provided.'], 400);
        }

        try {
            $query = $this->database->select('esncard_numbers', 'e');
            $query->condition('e.number', $card_number);
            $exists = $query->countQuery()->execute()->fetchField() > 0;

            if ($exists) {
                return new JsonResponse(['status' => 'error', 'message' => 'This ESNcard number already exists.'], 409);
            }
        } catch (Exception $e) {
            $this->logger->error('Failed to checking ESNcard @card: @message', [
                '@card' => $card_number,
                '@message' => $e->getMessage(),
            ]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem checking the card.'], 500);
        }

        $transaction = null;
        try {
            $transaction = $this->database->startTransaction();

            $query = $this->database->select('esncard_numbers', 'e');
            $query->addExpression('MAX(sequence)', 'max_seq');
            $max_sequence = (int)$query->execute()->fetchField();

            $this->database->insert('esncard_numbers')
                ->fields([
                    'number' => $card_number,
                    'sequence' => $max_sequence + 1,
                    'assigned' => 0,
                ])
                ->execute();
            return new JsonResponse(['status' => 'success', 'message' => 'The ESNcard was added successfully.'], 200);
        } catch (Exception $e) {
            $transaction?->rollBack();
            $this->logger->error('Failed to insert ESNcard @card: @message', [
                '@card' => $card_number,
                '@message' => $e->getMessage(),
            ]);
            return new JsonResponse(['status' => 'error', 'message' => 'There was a problem inserting the card.'], 500);
        }
    }
}