<?php

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for viewing submission details.
 */
class SubmissionController extends ControllerBase implements ContainerInjectionInterface
{
    /**
     * The database connection.
     *
     * @var Connection
     */
    protected Connection $database;

    /**
     * The current user.
     * @var AccountProxyInterface
     */
    protected $currentUser;

    /**
     * Constructs a SubmissionController object.
     *
     */
    public function __construct(
        Connection            $database,
        AccountProxyInterface $currentUser,
    )
    {
        $this->database = $database;
        $this->currentUser = $currentUser;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');

        /** @var AccountProxyInterface $currentUser */
        $currentUser = $container->get('current_user');
        return new static(
            $database,
            $currentUser
        );
    }

    /**
     * Preview a file in a modal.
     */
    public function preview(FileInterface $file): array
    {
        $url = $file->createFileUrl(FALSE);
        $absolute_url = $this->getAbsoluteUrl($url);
        $mime = $file->getMimeType();

        $build = [];

        if (str_starts_with($mime, 'image/')) {
            $build['image'] = [
                '#theme' => 'image',
                '#uri' => $absolute_url,
                '#attributes' => [
                    'style' => 'max-width: 100%; height: auto;',
                ],
            ];
        } elseif ($mime === 'application/pdf') {
            $build['iframe'] = [
                '#type' => 'inline_template',
                '#template' => '<iframe src="{{ url }}" width="100%" height="600px" style="border: none;"></iframe>',
                '#context' => [
                    'url' => $absolute_url,
                ],
            ];
        } else {
            $build['link'] = [
                '#type' => 'link',
                '#title' => $this->t('Click here to view file'),
                '#url' => Url::fromUri($absolute_url),
                '#attributes' => ['target' => '_blank'],
            ];
            $build['message'] = [
                '#markup' => '<p>' . $this->t('Preview not available for this file type.') . '</p>',
            ];
        }

        return $build;
    }

    /**
     * Helper to ensure absolute URL if needed, or just return.
     */
    private function getAbsoluteUrl($url): string
    {
        if (str_starts_with($url, '/')) {
            return 'base:' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * Views a submission in a modal.
     *
     * @param int $id
     *   The submission ID.
     *
     * @return array
     *   A render array suitable for a modal.
     * @throws Exception
     */
    public function viewSubmission(int $id): array
    {
        $application = $this->database->select('esn_membership_manager_applications', 'a')
            ->fields('a')
            ->condition('id', $id)
            ->execute()
            ->fetchAssoc();

        if (!$application) {
            return [
                '#markup' => $this->t('Application not found.'),
            ];
        }

        $labels = [
            'id' => $this->t('ID'),
            'name' => $this->t('Name'),
            'surname' => $this->t('Surname'),
            'email' => $this->t('Email'),
            'nationality' => $this->t('Nationality'),
            'dob' => $this->t('Date of Birth'),
            'mobility_status' => $this->t('Mobility Status'),
            'host_institution' => $this->t('Host Institution'),
            'approval_status' => $this->t('Approval Status'),
            'proof_fid' => $this->t('Proof of Mobility')
        ];
        if ($application['esncard']) {
            $labels += [
                'id_document_fid' => $this->t('ID Document'),
                'face_photo_fid' => $this->t('Profile Photo')
            ];
        }
        if ($application['pass'])
            $labels += ['pass_token' => $this->t('Pass Token')];
        if ($application['esncard']) {
            $labels += [
                'esncard_number' => $this->t('ESNcard Number'),
                'payment_link' => $this->t('Stripe Payment Link')
            ];
        }
        $labels += [
            'date_created' => $this->t('Created Date'),
            'date_approved' => $this->t('Date Approved')
        ];
        if ($application['esncard']) {
            $labels += ['date_paid' => $this->t('Date Paid')];
        }
        $labels += ['date_last_scanned' => $this->t('Date Last Scanned')];

        $proofURL = null;
        $idURL = null;
        $photoURL = null;

        $readOnlyKeys = [
            'id',
            'proof_fid',
            'id_document_fid',
            'face_photo_fid',
            'payment_link',
            'payment_link_id',
            'approval_status',
            'date_created',
            'date_paid',
            'date_approved'
        ];

        $fieldData = [];
        foreach ($application as $key => $value) {
            if (!$application['esncard'] && in_array($key, ['id_document_fid', 'face_photo_fid', 'esncard_number', 'payment_link', 'date_paid']))
                continue;

            if (!$application['pass'] && $key == "pass_token")
                continue;

            if (in_array($key, ['pass', 'esncard'])) continue;

            $label = $labels[$key] ?? $key;
            $displayValue = $value;

            if ($key == 'dob' && !empty($value)) {
                $timestamp = is_numeric($value) ? $value : strtotime($value);
                if ($timestamp) {
                    $displayValue = date('d/m/Y', $timestamp);
                }
            }

            if (str_contains($key, 'date') && !empty($value)) {
                $timestamp = is_numeric($value) ? $value : strtotime($value);
                if ($timestamp) {
                    $displayValue = date('d/m/Y H:i', $timestamp);
                }
            }

            if (in_array($key, ['proof_fid', 'id_document_fid', 'face_photo_fid'])) {
                if (!empty($value)) {
                    $url = $this->generateFileLink($value);

                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        switch ($key) {
                            case 'proof_fid':
                                $proofURL = $url;
                                break;
                            case 'id_document_fid':
                                $idURL = $url;
                                break;
                            case 'face_photo_fid':
                                $photoURL = $url;
                                break;
                        }
                        $displayValue = Link::fromTextAndUrl($url, Url::fromUri($url, ['attributes' => ['target' => '_blank']]))->toRenderable();
                    } else {
                        $displayValue = $url;
                    }
                } else {
                    continue;
                }
            }

            if ($key == 'payment_link') {
                if (!empty($value)) {
                    $displayValue = Link::fromTextAndUrl($value, Url::fromUri($value, ['attributes' => ['target' => '_blank']]))->toRenderable();
                } else {
                    $displayValue = '';
                }
            }

            $fieldData[] = [
                'key' => $key,
                'label' => $label,
                'value' => $displayValue,
                'readonly' => in_array($key, $readOnlyKeys)
            ];
        }

        return [
            '#theme' => 'emm_submission_view',
            '#id' => $id,
            '#fieldData' => $fieldData,
            '#urls' => [
                'proof' => $proofURL,
                'id' => $idURL,
                'photo' => $photoURL,
            ],
            '#permissions' => [
                'edit' => $this->currentUser->hasPermission('edit submission'),
                'approve' => $this->currentUser->hasPermission('approve submission'),
                'decline' => $this->currentUser->hasPermission('decline submission'),
            ],
            '#apiURLs' => [
                'update' => Url::fromRoute('esn_membership_manager.edit')->toString(),
                'status' => Url::fromRoute('esn_membership_manager.status')->toString()
            ]
        ];
    }

    /**
     * Helper function to generate file links.
     */
    protected function generateFileLink($file_id): string
    {
        if (empty($file_id)) {
            return $this->t('N/A');
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager()->getStorage('file')->load($file_id);
            if ($file) {
                return $file->createFileUrl(FALSE);
            }
        } catch (Exception) {
        }
        return $this->t('File not found');
    }

    /**
     * Display a success page for application submission.
     */
    public function successPage(): array
    {
        return [
            '#markup' => $this->t('<div><h3>Thank you for your application!</h3><p>We have successfully received your details. Please check your email for confirmation.</p><p><a href="/memberships/apply">Submit another application</a></p></div>'),
        ];
    }
}
