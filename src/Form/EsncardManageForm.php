<?php

namespace Drupal\esn_cyprus_pass_validation\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

class EsncardManageForm extends FormBase
{

    public function getFormId(): string
    {
        return 'esncard_manage_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state): array
    {

        // Bulk insert textarea
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

        // Table header
        $header = [
            'sequence' => $this->t('Sequence'),
            'number' => $this->t('Number'),
            'assigned' => $this->t('Assigned'),
            'operations' => $this->t('Operations'),
        ];

        // Load existing ESNcards
        $database = Drupal::database();
        $results = $database->select('esncard_numbers', 'e')
            ->fields('e', ['id', 'number', 'sequence', 'assigned'])
            ->orderBy('sequence')
            ->execute()
            ->fetchAll();

        $form['esncards_table'] = [
            '#type' => 'table',
            '#header' => $header,
        ];

        foreach ($results as $row) {
            $form['esncards_table'][$row->id]['sequence'] = [
                '#markup' => $row->sequence,
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
                    '#submit' => ['::updateEsncard'],
                    '#limit_validation_errors' => [['esncards_table', $row->id, 'number']],
                    '#esncard_id' => $row->id,
                ],
                'delete' => [
                    '#type' => 'submit',
                    '#value' => $this->t('Delete'),
                    '#name' => 'delete_' . $row->id,
                    '#submit' => ['::deleteEsncard'],
                    '#limit_validation_errors' => [], // prevents Insert/Update from running
                    '#esncard_id' => $row->id,
                ],
            ];
        }

        return $form;
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        // Required by FormBase but unused.
    }

    /**
     * Bulk insert handler.
     */
    public function submitBulkInsert(array &$form, FormStateInterface $form_state)
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

        $query = Drupal::database()->select('esncard_numbers', 'e');
        $query->addExpression('MAX(sequence)', 'max_seq');
        $max_sequence = (int)$query->execute()->fetchField();

        $sequence = $max_sequence + 1;
        $inserted = 0;

        foreach ($codes as $code) {
            if (!empty($code)) {
                Drupal::database()->insert('esncard_numbers')
                    ->fields([
                        'number' => $code,
                        'sequence' => $sequence,
                        'assigned' => 0,
                    ])
                    ->execute();
                $sequence++;
                $inserted++;
            }
        }

        $this->messenger()->addStatus($this->t('Inserted @count ESNcard numbers.', ['@count' => $inserted]));
        $form_state->setRebuild();
    }

    /**
     * Delete ESNcard.
     */
    public function deleteEsncard(array &$form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();

        // Only run if this Delete button was clicked
        if (!str_starts_with($trigger['#name'], 'delete_')) {
            return;
        }

        $id = $trigger['#esncard_id'];
        Drupal::database()->delete('esncard_numbers')->condition('id', $id)->execute();
        $this->messenger()->addStatus($this->t('Deleted ESNcard ID @id.', ['@id' => $id]));
        $form_state->setRebuild();
    }

    /**
     * Update ESNcard number.
     */
    public function updateEsncard(array &$form, FormStateInterface $form_state)
    {
        $trigger = $form_state->getTriggeringElement();

        // Only run if this Update button was clicked
        if (!str_starts_with($trigger['#name'], 'edit_')) {
            return;
        }

        $id = $trigger['#esncard_id'];
        $value = $form_state->getValue(['esncards_table', $id, 'number']);

        if ($value === null || $value === '') {
            $this->messenger()->addError($this->t('ESNcard number cannot be empty.'));
            return;
        }

        Drupal::database()->update('esncard_numbers')
            ->fields(['number' => $value])
            ->condition('id', $id)
            ->execute();

        $this->messenger()->addStatus($this->t('Updated ESNcard ID @id.', ['@id' => $id]));
        $form_state->setRebuild();
    }

}

