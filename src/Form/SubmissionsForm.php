<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;

class SubmissionsForm extends FormBase
{
    protected $configFactory;
    protected EntityTypeManagerInterface $entityTypeManager;
    protected Connection $database;
    protected $requestStack;
    protected ActionManager $actionManager;
    protected LoggerChannelInterface $logger;

    public function __construct(
        ConfigFactoryInterface        $configFactory,
        EntityTypeManagerInterface    $entityTypeManager,
        Connection                    $database,
        RequestStack                  $requestStack,
        ActionManager                 $actionManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->configFactory = $configFactory;
        $this->entityTypeManager = $entityTypeManager;
        $this->database = $database;
        $this->requestStack = $requestStack;
        $this->actionManager = $actionManager;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var RequestStack $requestStack */
        $requestStack = $container->get('request_stack');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $configFactory,
            $entityTypeManager,
            $database,
            $requestStack,
            $actionManager,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_submissions';
    }

    /**
     * Builds the submissions list page.
     */
    public function buildForm(array $form, FormStateInterface $form_state): JsonResponse|array
    {
        $params = $this->requestStack->getCurrentRequest()->query;
        $search = $params->get('search', '');
        $status = $params->get('approval_status', '');
        $esncard = $params->get('esncard', '');
        $pass = $params->get('pass', '');
        $sort_by = $params->get('sort_by', 'created');
        $sortOrder = $params->get('sort_order', 'DESC');

        $form['#attached']['library'][] = 'core/drupal.dialog.ajax';

        $form['filters'] = [
            '#type' => 'details',
            '#title' => $this->t('Filter submissions'),
            '#open' => TRUE,
            '#weight' => -20,
        ];

        $form['filters']['container'] = [
            '#type' => 'container',
        ];

        $form['filters']['container']['search'] = [
            '#type' => 'search',
            '#title' => $this->t('Search'),
            '#placeholder' => $this->t('Search by name, surname, email...'),
            '#default_value' => $search,
        ];

        $form['filters']['container']['status'] = [
            '#type' => 'select',
            '#title' => $this->t('Status'),
            '#options' => [
                '' => $this->t('- Any Status -'),
                'Pending' => $this->t('Pending'),
                'Approved' => $this->t('Approved'),
                'Declined' => $this->t('Declined'),
                'Paid' => $this->t('Paid'),
                'Issued' => $this->t('Issued'),
                'Delivered' => $this->t('Delivered'),
            ],
            '#default_value' => $status,
        ];

        $form['filters']['container']['esncard'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('ESNcard'),
            '#default_value' => $esncard,
        ];

        $form['filters']['container']['pass'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Pass'),
            '#default_value' => $pass,
        ];

        $form['filters']['container']['sort_by'] = [
            '#type' => 'select',
            '#title' => $this->t('Sort by'),
            '#options' => [
                'created' => $this->t('Created Date'),
                'date_paid' => $this->t('Date Paid'),
                'esncard_number' => $this->t('ESNcard Number'),
            ],
            '#default_value' => $sort_by,
        ];

        $form['filters']['container']['sort_order'] = [
            '#type' => 'select',
            '#title' => $this->t('Sort order'),
            '#options' => [
                'DESC' => $this->t('Descending'),
                'ASC' => $this->t('Ascending'),
            ],
            '#default_value' => $sortOrder,
        ];

        $form['filters']['container']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Filter'),
            '#submit' => ['::filterFormSubmit'],
        ];

        $form['filters']['container']['reset'] = [
            '#type' => 'submit',
            '#value' => $this->t('Reset'),
            '#submit' => ['::filterFormReset'],
        ];

        $form['actions'] = [
            '#type' => 'details',
            '#title' => $this->t('Actions'),
            '#open' => TRUE,
            '#weight' => -10,
        ];

        $action_plugin_ids = [
            'esn_membership_manager_approve',
            'esn_membership_manager_decline',
            'esn_membership_manager_delete',
            'esn_membership_manager_mark_paid',
            'esn_membership_manager_issue',
            'esn_membership_manager_deliver',
            'esn_membership_manager_blacklist'
        ];

        $options = ['' => $this->t('- Select an action -')];
        foreach ($action_plugin_ids as $id) {
            if ($this->actionManager->hasDefinition($id)) {
                try {
                    /** @var ActionBase $action */
                    $action = $this->actionManager->createInstance($id);
                    if ($action->access(NULL, $this->currentUser())) {
                        $definition = $this->actionManager->getDefinition($id);
                        $options[$id] = $definition['label'];
                    }
                } catch (Exception $e) {
                    $this->logger->warning('Could not check access for action @id: @message', ['@id' => $id, '@message' => $e->getMessage()]);
                }
            } else {
                $this->logger->warning('Missing action plugin definition: @id', ['@id' => $id]);
            }
        }

        $form['actions']['container'] = [
            '#type' => 'container',
            '#attributes' => ['class' => ['container-inline']],
        ];

        $form['actions']['container']['action'] = [
            '#type' => 'select',
            '#title' => $this->t('Action'),
            '#options' => $options,
        ];

        $form['actions']['container']['submit'] = [
            '#type' => 'submit',
            '#value' => $this->t('Apply to selected items'),
        ];

        $header = [
            'approval_status' => $this->t('Status'),
            'name' => $this->t('Name'),
            'surname' => $this->t('Surname'),
            'nationality' => $this->t('Nationality'),
            'email' => $this->t('Email'),
            'esncard' => $this->t('ESNcard'),
            'pass' => $this->t('Pass'),
            'proof' => $this->t('Proof'),
            'id_doc' => $this->t('ID'),
            'profile_img' => $this->t('Image'),
            'operations' => $this->t('Operations'),
        ];

        $query = $this->database->select('esn_membership_manager_applications', 'a');
        $query->fields('a');

        if (!empty($search)) {
            $orGroup = $query->orConditionGroup()
                ->condition('name', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
                ->condition('surname', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
                ->condition('email', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
                ->condition('esncard_number', '%' . $this->database->escapeLike($search) . '%', 'LIKE')
                ->condition('pass_token', '%' . $this->database->escapeLike($search) . '%', 'LIKE');
            $query->condition($orGroup);
        }

        if (!empty($status)) {
            $query->condition('approval_status', $status);
        }

        if (!empty($esncard)) {
            $query->condition('esncard', 1);
        }

        if (!empty($pass)) {
            $query->condition('pass', 1);
        }

        $pagedQuery = $query->extend('Drupal\Core\Database\Query\PagerSelectExtender')->limit(20);

        $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
        switch ($sort_by) {
            case 'created':
                $pagedQuery->orderBy('a.date_created', $sortOrder);
                break;
            case 'date_paid':
                $pagedQuery->orderBy('a.date_paid', $sortOrder);
                break;
            case 'esncard_number':
                $pagedQuery->orderBy('a.esncard_number', $sortOrder);
                break;
            default:
                $pagedQuery->orderBy('a.date_created', 'DESC');
        }

        $results = $pagedQuery->execute()->fetchAll();

        $rows = [];
        foreach ($results as $row) {
            $rows[$row->id] = [
                'approval_status' => $row->approval_status ?? '',
                'name' => $row->name ?? '',
                'surname' => $row->surname ?? '',
                'email' => $row->email ?? '',
                'nationality' => $row->nationality ?? '',
                'esncard' => [
                    'data' => [
                        '#markup' => Markup::create('<input type="checkbox" onclick="return false;" style="cursor: default;" ' . ($row->esncard ? 'checked' : '') . '>'),
                    ],
                ],
                'pass' => [
                    'data' => [
                        '#markup' => Markup::create('<input type="checkbox" onclick="return false;" style="cursor: default;" ' . ($row->pass ? 'checked' : '') . '>'),
                    ],
                ],
                'proof' => $this->generateFilePreview($row->proof_fid),
                'id_doc' => $this->generateFilePreview($row->id_document_fid),
                'profile_img' => $this->generateFilePreview($row->face_photo_fid),
                'operations' => [
                    'data' => [
                        '#type' => 'link',
                        '#title' => $this->t('View'),
                        '#url' => Url::fromRoute('esn_membership_manager.submission_view', ['id' => $row->id]),
                        '#attributes' => [
                            'class' => ['use-ajax', 'button', 'button--small'],
                            'data-dialog-type' => 'modal',
                            'data-dialog-options' => Json::encode(['width' => '90%']),
                        ],
                    ],
                ],
            ];
        }

        $form['table'] = [
            '#type' => 'tableselect',
            '#header' => $header,
            '#options' => $rows,
            '#empty' => $this->t('No submissions found.'),
        ];
        $form['pager'] = [
            '#type' => 'pager',
        ];

        return $form;
    }

    function generateFilePreview($file_id): array|string
    {
        if (empty($file_id)) {
            return '';
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($file_id);
            if (!$file) return '';
            return [
                'data' => [
                    '#type' => 'link',
                    '#title' => $this->t('Preview'),
                    '#url' => Url::fromRoute('esn_membership_manager.file_preview', ['file' => $file->id()]),
                    '#attributes' => [
                        'class' => ['use-ajax', 'button', 'button--small'],
                        'data-dialog-type' => 'modal',
                        'data-dialog-options' => Json::encode(['width' => 700, 'minHeight' => 500]),
                    ],
                ]
            ];
        } catch (InvalidPluginDefinitionException|PluginNotFoundException) {
            $this->logger->warning('File ID @id was unable to be retrieved.', ['@id' => $file_id]);
            return '';
        }
    }

    public function filterFormSubmit(array &$form, FormStateInterface $form_state): void
    {
        $values = $form_state->getValues();
        $query_params = [];
        if (!empty($values['search'])) $query_params['search'] = $values['search'];
        if (!empty($values['status'])) $query_params['status'] = $values['status'];
        if (!empty($values['esncard'])) $query_params['esncard'] = $values['esncard'];
        if (!empty($values['pass'])) $query_params['pass'] = $values['pass'];
        if (!empty($values['sort_by'])) $query_params['sort_by'] = $values['sort_by'];
        if (!empty($values['sort_order'])) $query_params['sort_order'] = $values['sort_order'];
        $form_state->setRedirect('esn_membership_manager.submissions', [], ['query' => $query_params]);
    }

    public function filterFormReset(array &$form, FormStateInterface $form_state): void
    {
        $form_state->setRedirect('esn_membership_manager.submissions', [], ['query' => []]);
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
        $trigger = $form_state->getTriggeringElement()['#value'];
        if ($trigger == $this->t('Filter') || $trigger == $this->t('Reset')) {
            return;
        }

        $action_id = $form_state->getValue('action');
        $selected_ids = array_filter($form_state->getValue('table'));

        if (empty($selected_ids) || empty($action_id)) {
            $this->messenger()->addWarning($this->t('No items selected or no action chosen.'));
            return;
        }

        $currentID = '';
        try {
            if ($this->actionManager->hasDefinition($action_id)) {
                /** @var ActionBase $action */
                $action = $this->actionManager->createInstance($action_id);

                foreach ($selected_ids as $id => $value) {
                    $currentID = $id;
                    if ($action->access($id, $this->currentUser())) {
                        $action->execute($id);
                    }
                }

                $this->messenger()->addStatus($this->t('Action applied to @count items.', ['@count' => count($selected_ids)]));
            } else {
                $this->messenger()->addError($this->t('Action plugin not found.'));
            }

        } catch (Exception $e) {
            $this->logger->error('Failed to execute bulk action @action: @message', [
                '@action' => $action_id,
                '@message' => $e->getMessage()
            ]);
            $this->messenger()->addError($this->t('An error occurred while processing the action on ID @id: @message', ['@id' => $currentID, '@message' => $e->getMessage()]));
        }
    }
}