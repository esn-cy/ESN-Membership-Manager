<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Form;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Action\ActionManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassField;
use Drupal\esn_membership_manager\Entity\GuestPass\GuestPassStorage;
use Drupal\esn_membership_manager\Plugin\Action\ApproveGuestPass;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ViewGuestPassesForm extends FormBase
{
    protected GuestPassStorage $guestPassStorage;
    protected ApproveGuestPass $approveGuestPass;
    protected LoggerChannelInterface $logger;

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws PluginException
     */
    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        ActionManager                 $actionManager,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        /** @var GuestPassStorage $guestPassStorage */
        $guestPassStorage = $entityTypeManager->getStorage('membership_guest');

        /** @var ApproveGuestPass $approveGuestPass */
        $approveGuestPass = $actionManager->createInstance('esn_membership_manager_approve_guest');

        $this->guestPassStorage = $guestPassStorage;
        $this->approveGuestPass = $approveGuestPass;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    /**
     * @throws PluginException
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var ActionManager $actionManager */
        $actionManager = $container->get('plugin.manager.action');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entityTypeManager,
            $actionManager,
            $loggerFactory
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getFormId(): string
    {
        return 'esn_membership_manager_view_guest_passes';
    }

    /**
     * Builds the applications list page.
     */
    public function buildForm(array $form, FormStateInterface $form_state): JsonResponse|array
    {
        $params = $this->getRequest()->query;
        $search = $params->get('search', '');
        $status = $params->get('status', '');
        $sortBy = $params->get('sort_by', 'created');
        $sortOrder = $params->get('sort_order', 'DESC');

        $form['filters'] = [
            '#type' => 'details',
            '#title' => $this->t('Filter Guest Passes'),
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
                'Redeemed' => $this->t('Redeemed'),
                'Expired' => $this->t('Expired'),
            ],
            '#default_value' => $status,
        ];

        $form['filters']['container']['sort_by'] = [
            '#type' => 'select',
            '#title' => $this->t('Sort by'),
            '#options' => [
                'created' => $this->t('Created Date'),
                'approved' => $this->t('Approved Date'),
                'redeemed' => $this->t('Redeemed Date'),
            ],
            '#default_value' => $sortBy,
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

        $header = [
            'approval_status' => $this->t('Status'),
            'referrer' => $this->t('Referrer'),
            'name' => $this->t('Name'),
            'surname' => $this->t('Surname'),
            'email' => $this->t('Email'),
            'reason' => $this->t('Reason'),
            'operations' => $this->t('Operations'),
        ];

        $guestPasses = $this->guestPassStorage->search($search, $status, $sortOrder, $sortBy);

        $canManage = $this->approveGuestPass->access(null, $this->currentUser());

        $form['table'] = [
            '#type' => 'table',
            '#header' => $header,
            '#empty' => $this->t('No Guest Passes found.'),
        ];

        foreach ($guestPasses as $guestPass) {
            $referrer = $guestPass->getReferer();
            $status = $guestPass->getApprovalStatus();

            $form['table'][$guestPass->id()] = [
                '#attributes' => ['style' => 'vertical-align: middle;'],

                'approval_status' => ['#plain_text' => $status],
                'referrer' => [
                    '#type' => 'link',
                    '#title' => $referrer->getFullName(),
                    '#url' => Url::fromRoute('esn_membership_manager.application_view', ['id' => $referrer->id()]),
                    '#attributes' => [
                        'target' => '_blank'
                    ],
                ],
                'name' => ['#plain_text' => $guestPass->getValue(GuestPassField::Name) ?? ''],
                'surname' => ['#plain_text' => $guestPass->getValue(GuestPassField::Surname) ?? ''],
                'email' => ['#plain_text' => $guestPass->getValue(GuestPassField::Email) ?? ''],
                'reason' => ['#plain_text' => $guestPass->getValue(GuestPassField::Reason) ?? ''],
                'operations' => [
                    'edit' => [
                        '#type' => 'submit',
                        '#value' => $this->t('Approve'),
                        '#name' => 'approve_' . $guestPass->id(),
                        '#submit' => ['::approveGuestPass'],
                        '#attributes' => [
                            'class' => ['button', 'button--small'],
                        ],
                        '#limit_validation_errors' => [],
                    ],
                    'delete' => [
                        '#type' => 'submit',
                        '#value' => $this->t('Delete'),
                        '#name' => 'delete_' . $guestPass->id(),
                        '#submit' => ['::deleteGuestPass'],
                        '#attributes' => [
                            'class' => ['button', 'button--small', 'button--danger'],
                            'onclick' => 'if(!confirm("Are you sure you want to delete this Guest Pass?")){return false;}',
                        ],
                        '#limit_validation_errors' => [],
                    ],
                ],
            ];

            if ($status !== 'Pending') {
                $form['table'][$guestPass->id()]['operations']['edit']['#attributes']['onclick'] = 'return false;';
            }

            if ($canManage === false) {
                unset($form['table'][$guestPass->id()]['operations']);
            }
        }

        $form['pager'] = [
            '#type' => 'pager',
        ];

        return $form;
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function filterFormSubmit(array &$form, FormStateInterface $form_state): void
    {
        $values = $form_state->getValues();
        $queryParams = [];
        if (!empty($values['search'])) $queryParams['search'] = $values['search'];
        if (!empty($values['status'])) $queryParams['status'] = $values['status'];
        if (!empty($values['sort_by'])) $queryParams['sort_by'] = $values['sort_by'];
        if (!empty($values['sort_order'])) $queryParams['sort_order'] = $values['sort_order'];
        $form_state->setRedirect('esn_membership_manager.view_guest_passes', [], ['query' => $queryParams]);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function filterFormReset(array &$form, FormStateInterface $form_state): void
    {
        $form_state->setRedirect('esn_membership_manager.view_guest_passes', [], ['query' => []]);
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function approveGuestPass(array &$form, FormStateInterface $form_state): void
    {
        $triggeringElement = $form_state->getTriggeringElement();
        $name = $triggeringElement['#name'] ?? '';

        if (str_starts_with($name, 'approve_')) {
            $id = substr($name, 8);
            try {
                $guestPass = $this->guestPassStorage->load($id);
                if ($guestPass) {
                    $guestPass->delete();
                    $this->messenger()->addStatus($this->t('Guest Pass successfully approved.'));
                }
            } catch (Exception $e) {
                $this->messenger()->addError($this->t('Failed to approve Guest Pass: @message', ['@message' => $e->getMessage()]));
            }

            $form_state->setRebuild();
        }
    }

    /** @noinspection PhpUnusedParameterInspection */
    public function deleteGuestPass(array &$form, FormStateInterface $form_state): void
    {
        $triggeringElement = $form_state->getTriggeringElement();
        $name = $triggeringElement['#name'] ?? '';

        if (str_starts_with($name, 'delete_')) {
            $id = substr($name, 7);
            try {
                $guestPass = $this->guestPassStorage->load($id);
                if ($guestPass) {
                    $guestPass->delete();
                    $this->messenger()->addStatus($this->t('Guest Pass successfully deleted.'));
                }
            } catch (Exception $e) {
                $this->messenger()->addError($this->t('Failed to delete Guest Pass: @message', ['@message' => $e->getMessage()]));
            }

            $form_state->setRebuild();
        }
    }

    public function submitForm(array &$form, FormStateInterface $form_state): void
    {
    }
}