<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Service\ESNcardService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ESNcardNumberForm extends FormBase
{
    protected Connection $database;
    protected LoggerChannelInterface $logger;
    protected ESNcardService $esncardService;

    public function __construct(
        Connection                    $database,
        LoggerChannelFactoryInterface $loggerFactory,
        ESNcardService                $esncardService,
    )
    {
        $this->database = $database;
        $this->logger = $loggerFactory->get('esn_membership_manager');
        $this->esncardService = $esncardService;
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var LoggerChannelFactoryInterface $logger */
        $logger = $container->get('logger.factory');

        /** @var ESNcardService $esncardService */
        $esncardService = $container->get('esn_membership_manager.esncard_service');

        return new static(
            $database,
            $logger,
            $esncardService,
        );
    }

    public function getFormId(): string
    {
        return 'esncard_number_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {
        $form['cards'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Bulk ESNcard Numbers'),
            '#description' => $this->t('Enter one ESNcard number per line, or comma-separated.'),
            '#rows' => 10,
        ];

        $form['submit_new'] = [
            '#type' => 'submit',
            '#value' => $this->t('Insert ESNcards'),
            '#submit' => ['::submitBulkInsert'],
            '#name' => 'submit_new', // important for triggering check
        ];

        $header = [
            'id' => $this->t('ID'),
            'number' => $this->t('Number'),
            'assigned' => $this->t('Assigned'),
            'operations' => $this->t('Operations'),
        ];

        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
        $query = $this->database->select('esn_membership_manager_cards', 'e')
            ->fields('e', ['id', 'number', 'assigned'])
            ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
            ->limit(50)
            ->orderBy('id');

        $results = $query->execute()->fetchAll();

        $form['esncards_table'] = [
            '#type' => 'table',
            '#header' => $header,
        ];

        foreach ($results as $row) {
            $form['esncards_table'][$row->id]['id'] = [
                '#markup' => $row->id,
            ];

            $form['esncards_table'][$row->id]['number'] = [
                '#type' => 'textfield',
                '#default_value' => $row->number,
                '#size' => 20,
            ];

            $form['esncards_table'][$row->id]['assigned'] = [
                '#markup' => $row->assigned ? $this->t('Yes') : $this->t('No'),
            ];

            // Operations buttons
            $form['esncards_table'][$row->id]['operations'] = [
                'edit' => [
                    '#type' => 'submit',
                    '#value' => $this->t('Update'),
                    '#name' => 'edit_' . $row->id,
                    '#submit' => ['::updateESNcard'],
                    '#limit_validation_errors' => [['esncards_table', $row->id, 'number']],
                    '#esncard_id' => $row->id,
                ],
                'delete' => [
                    '#type' => 'submit',
                    '#value' => $this->t('Delete'),
                    '#name' => 'delete_' . $row->id,
                    '#submit' => ['::deleteESNcard'],
                    '#limit_validation_errors' => [], // prevents Insert/Update from running
                    '#esncard_id' => $row->id,
                    '#attributes' => [
                        'onclick' => 'return confirm("Are you sure you want to delete this card?");',
                    ],
                ],
            ];
        }

        $form['pager'] = [
            '#type' => 'pager',
        ];

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Required by FormBase but unused.
    }

    /**
     * Bulk insert handler.
     */
    public function submitBulkInsert(array &$form, FormStateInterface $form_state): void
    {
        $trigger = $form_state->getTriggeringElement();

        // Only run if the Insert button itself was clicked
        if ($trigger['#name'] !== 'submit_new') {
            return;
        }

        $input = $form_state->getValue('cards');
        $codes = preg_split('/[\r\n,]+/', trim($input));
        $codes = array_filter(array_map('trim', $codes));

        if (empty($codes)) {
            $this->messenger()->addError($this->t('No ESNcard numbers provided.'));
            return;
        }

        $issues = $this->esncardService->addESNcards($codes);
        foreach ($issues as $issue) {
            switch ($issue['issue']) {
                case 'invalid':
                    $this->messenger()->addWarning($this->t('ESNcard number @cardNumber is not a valid ESNcard number.', ['@cardNumber' => $issue['number']]));
                    break;
                case 'duplicate':
                    $this->messenger()->addWarning($this->t('ESNcard number @cardNumber already exists.', ['@cardNumber' => $issue['number']]));
                    break;
                case 'database':
                    $this->messenger()->addError($this->t('ESNcard number @cardNumber failed to insert into the database.', ['@cardNumber' => $issue['number']]));
                    break;
            }
        }

        if (count($codes) == count($issues)) {
            return;
        }

        $this->messenger()->addStatus($this->t('Inserted @count ESNcard numbers.', ['@count' => (count($codes) - count($issues))]));
        $form_state->setRebuild();
    }

    /**
     * Delete ESNcard.
     */
    public function deleteESNcard(array &$form, FormStateInterface $form_state): void
    {
        $trigger = $form_state->getTriggeringElement();

        // Only run if this Delete button was clicked
        if (!str_starts_with($trigger['#name'], 'delete_')) {
            return;
        }

        $id = $trigger['#esncard_id'];
        try {
            $this->database->delete('esn_membership_manager_cards')->condition('id', $id)->execute();
        } catch (Exception $e) {
            $this->messenger()->addError($e->getMessage());
            $this->logger->error('Failed to delete ESNcard number with ID @number. Error: @error', ['@number' => $id, '@error' => $e->getMessage()]);
            return;
        }

        $this->messenger()->addStatus($this->t('Deleted ESNcard ID @id.', ['@id' => $id]));
        $form_state->setRebuild();
    }

    /**
     * Update ESNcard number.
     */
    public function updateESNcard(array &$form, FormStateInterface $form_state): void
    {
        $trigger = $form_state->getTriggeringElement();

        // Only run if this Update button was clicked
        if (!str_starts_with($trigger['#name'], 'edit_')) {
            return;
        }

        $id = $trigger['#esncard_id'];
        $value = trim($form_state->getValue(['esncards_table', $id, 'number']));

        if (empty($value)) {
            $this->messenger()->addError($this->t('ESNcard number cannot be empty.'));
            return;
        }

        try {
            $query = $this->database->select('esn_membership_manager_cards', 'e');
            $exists = $query->condition('number', $value)
                ->condition('id', $id, '<>')
                ->countQuery()
                ->execute()
                ->fetchField();
        } catch (Exception $e) {
            $this->messenger()->addError($e->getMessage());
            $this->logger->error('Failed to update the ESNcard number: ' . $e->getMessage());
            return;
        }

        if ($exists) {
            $this->messenger()->addError($this->t('A duplicate ESNcard number already exists.'));
            return;
        }

        try {
            $this->database->update('esn_membership_manager_cards')
                ->fields(['number' => $value])
                ->condition('id', $id)
                ->execute();
        } catch (Exception $e) {
            $this->messenger()->addError($e->getMessage());
            $this->logger->error('Failed to update ESNcard number @number. Error: @error', ['@number' => $value, '@error' => $e->getMessage()]);
            return;
        }

        $this->messenger()->addStatus($this->t('Updated ESNcard ID @id.', ['@id' => $id]));
        $form_state->setRebuild();
    }
}