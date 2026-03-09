<?php

namespace Drupal\esn_membership_manager\Controller;

use DateInterval;
use DateTime;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use TCPDF;

/**
 * Controller for viewing submission details.
 */
class SubmissionController extends ControllerBase implements ContainerInjectionInterface
{
    protected $configFactory;
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

    protected $entityTypeManager;

    protected FileSystemInterface $fileSystem;

    /**
     * Constructs a SubmissionController object.
     *
     */
    public function __construct(
        ConfigFactoryInterface     $configFactory,
        Connection                 $database,
        AccountProxyInterface      $currentUser,
        EntityTypeManagerInterface $entity_type_manager,
        FileSystemInterface        $fileSystem
    )
    {
        $this->configFactory = $configFactory;
        $this->database = $database;
        $this->currentUser = $currentUser;
        $this->entityTypeManager = $entity_type_manager;
        $this->fileSystem = $fileSystem;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {
        /** @var ConfigFactoryInterface $configFactory */
        $configFactory = $container->get('config.factory');

        /** @var Connection $database */
        $database = $container->get('database');

        /** @var AccountProxyInterface $currentUser */
        $currentUser = $container->get('current_user');

        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var FileSystemInterface $fileSystem */
        $fileSystem = $container->get('file_system');

        return new static(
            $configFactory,
            $database,
            $currentUser,
            $entityTypeManager,
            $fileSystem
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
                'crop' => Url::fromRoute('esn_membership_manager.crop')->toString(),
                'status' => Url::fromRoute('esn_membership_manager.status')->toString()
            ],
            '#is_paid' => $application['approval_status'] == "Paid" || $application['approval_status'] == "Issued" || $application['approval_status'] == "Delivered",
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

    /**
     * Views the ESNcard for a submission.
     *
     * @param int $id
     *   The submission ID.
     *
     * @return array
     *   A render array.
     * @throws Exception
     */
    public function viewESNcard(int $id): array
    {
        $moduleConfig = $this->configFactory->get('esn_membership_manager.settings');

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

        $dob = explode('-', $application['dob']);

        $paidDate = new DateTime($application['date_paid']);
        $paidDate->setTime(0, 0);
        $expiryDate = (clone $paidDate)->add(new DateInterval("P1Y"))->format('Y-m-d');
        $doe = explode('-', $expiryDate);

        $section = preg_replace('/^' . preg_quote('ESN ', '/') . '/', '', $moduleConfig->get('organization_name') ?? 'ESN');

        return [
            '#theme' => 'emm_esncard',
            '#face_photo_link' => $this->generateFileLink($application['face_photo_fid']),
            '#full_name' => $application['name'] . ' ' . $application['surname'],
            '#nationality' => $application['nationality'],
            '#dob_day' => $dob[2],
            '#dob_month' => $dob[1],
            '#dob_year' => substr($dob[0], -2, 2),
            '#host_institution' => $application['host_institution'],
            '#section' => $section,
            '#doe_day' => $doe[2],
            '#doe_month' => $doe[1],
            '#doe_year' => substr($doe[0], -2, 2),
            '#esncard_number' => $application['esncard_number'] ?? '',
        ];
    }

    /**
     * Generates a PDF containing a grid of face photos for Paid applications.
     *
     * @return Response
     *   A Symfony Response carrying the PDF.
     * @throws Exception
     */
    public function generateFacePDF(Request $request): Response
    {
        $query_params = $request->query->all();
        $application_ids = $query_params['id'] ?? [];

        if (!is_array($application_ids)) {
            $application_ids = [$application_ids];
        }

        $application_ids = array_filter(array_map('intval', $application_ids));

        if (empty($application_ids)) {
            $applications = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['face_photo_fid', 'esncard_number'])
                ->condition('approval_status', 'Paid')
                ->condition('esncard', 1)
                ->isNotNull('face_photo_fid')
                ->orderBy('esncard_number')
                ->execute()
                ->fetchAll();
        } else {
            $applications = $this->database->select('esn_membership_manager_applications', 'a')
                ->fields('a', ['face_photo_fid', 'esncard_number'])
                ->condition('id', $application_ids, 'IN')
                ->isNotNull('face_photo_fid')
                ->orderBy('esncard_number')
                ->execute()
                ->fetchAll();
        }

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();
        $pdf->SetMargins(10, 10, 10);

        $imageHeight = 35;
        $availableWidth = 190;

        $currentX = 10;
        $currentY = 10;

        foreach ($applications as $app) {
            $fileID = $app->face_photo_fid;
            if (!$fileID) {
                continue;
            }

            /** @var FileInterface $file */
            $file = $this->entityTypeManager->getStorage('file')->load($fileID);
            if (!$file) {
                continue;
            }

            $uri = $file->getFileUri();
            $path = $this->fileSystem->realpath($uri);

            if (!file_exists($path)) {
                continue;
            }

            $sizes = @getimagesize($path);
            if ($sizes === false) {
                continue;
            }

            $origW = $sizes[0];
            $origH = $sizes[1];

            $targetWidth = ($origW / $origH) * $imageHeight;

            if (($currentX + $targetWidth) > (10 + $availableWidth)) {
                $currentX = 10;
                $currentY += $imageHeight;

                if (($currentY + $imageHeight) > (297 - 10)) {
                    $pdf->AddPage();
                }
            }

            $pdf->Image($path, $currentX, $currentY, $targetWidth, $imageHeight, '', '', '', true, 300, '', false, false, 1);

            $currentX += $targetWidth;
        }

        $pdfContent = $pdf->Output('esncard_images.pdf', 'S');

        $response = new Response($pdfContent);

        // Set Headers
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'esncard_images.pdf'
        );
        $response->headers->set('Content-Disposition', $disposition);
        $response->headers->set('Content-Type', 'application/pdf');

        return $response;
    }
}
