<?php

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\TranslatableMarkup;
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
     * Constructs a SubmissionController object.
     *
     * @param Connection $database
     *   The database connection.
     */
    public function __construct(Connection $database)
    {
        $this->database = $database;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var Connection $database */
        $database = $container->get('database');
        return new static(
            $database
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
        $submission = $this->database->select('esn_membership_manager_applications', 'a')
            ->fields('a')
            ->condition('id', $id)
            ->execute()
            ->fetchAssoc();

        if (!$submission) {
            return [
                '#markup' => $this->t('Submission not found.'),
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
        if ($submission['esncard']) {
            $labels += [
                'id_document_fid' => $this->t('ID Document'),
                'face_photo_fid' => $this->t('Profile Photo')
            ];
        }
        if ($submission['pass'])
            $labels += ['pass_token' => $this->t('Pass Token')];
        if ($submission['esncard']) {
            $labels += [
                'esncard_number' => $this->t('ESNcard Number'),
                'payment_link' => $this->t('Stripe Payment Link')
            ];
        }
        $labels += [
            'date_created' => $this->t('Created Date'),
            'date_approved' => $this->t('Date Approved')
        ];
        if ($submission['esncard']) {
            $labels += ['date_paid' => $this->t('Date Paid')];
        }
        $labels += ['date_last_scanned' => $this->t('Date Last Scanned')];

        $rows = [];
        foreach ($submission as $key => $value) {
            if (!$submission['esncard'] && in_array($key, ['id_document_fid', 'face_photo_fid', 'esncard_number', 'payment_link', 'date_paid']))
                continue;

            if (!$submission['pass'] && $key == "pass_token")
                continue;

            if (in_array($key, ['pass', 'esncard'])) continue;

            $label = $labels[$key] ?? $key;
            $display_value = $value;

            if ($key == 'dob' && !empty($value)) {
                $timestamp = is_numeric($value) ? $value : strtotime($value);
                if ($timestamp) {
                    $display_value = date('d/m/Y', $timestamp);
                }
            }

            if (str_contains($key, 'date') && !empty($value)) {
                $timestamp = is_numeric($value) ? $value : strtotime($value);
                if ($timestamp) {
                    $display_value = date('d/m/Y H:i', $timestamp);
                }
            }

            if (in_array($key, ['id_document_fid', 'face_photo_fid', 'proof_fid'])) {
                if (!empty($value))
                    $display_value = $this->generateFileLink($value);
            }

            if ($key == 'payment_link') {
                if (!empty($value)) {
                    $display_value = Link::fromTextAndUrl($value, Url::fromUri($value, ['attributes' => ['target' => '_blank']]))->toRenderable();
                } else {
                    $display_value = '';
                }
            }

            $rows[] = [
                [
                    'data' => $label,
                    'header' => true,
                    'style' => 'font-weight: bold; width: 30%;',
                ],
                ['data' => $display_value],
            ];
        }

        $build['details'] = [
            '#type' => 'table',
            '#rows' => $rows,
            '#attributes' => ['class' => ['submission-details-table']],
        ];

        return $build;
    }

    /**
     * Helper function to generate file links.
     */
    protected function generateFileLink($file_id): array|TranslatableMarkup
    {
        if (empty($file_id)) {
            return $this->t('N/A');
        }

        try {
            /** @var FileInterface $file */
            $file = $this->entityTypeManager()->getStorage('file')->load($file_id);
            if ($file) {
                $url = $file->createFileUrl(FALSE);
                $download_url = Url::fromUri($this->getAbsoluteUrl($url), ['attributes' => ['target' => '_blank']]);

                return [
                    '#type' => 'link',
                    '#title' => $file->getFilename(),
                    '#url' => $download_url,
                    '#attributes' => [
                        'class' => ['file-download-link'],
                        'style' => 'margin-right: 10px;',
                    ],
                ];
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
