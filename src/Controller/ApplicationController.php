<?php /** @noinspection PhpUnused */

namespace Drupal\esn_membership_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\file\FileInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use TCPDF;

/**
 * Controller for viewing application details.
 */
class ApplicationController extends ControllerBase implements ContainerInjectionInterface
{
    protected FileService $fileService;

    /**
     * Constructs a ApplicationController object.
     *
     */
    public function __construct(
        FileService $fileService
    )
    {
        $this->fileService = $fileService;
    }

    /**
     * {@inheritdoc}
     */
    public static function create(ContainerInterface $container): self
    {

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        return new static(
            $fileService
        );
    }

    /**
     * Preview a file in a modal.
     */
    public function preview(FileInterface $file): array
    {
        $url = $file->createFileUrl(FALSE);
        $absoluteUrl = $this->getAbsoluteUrl($url);
        $mime = $file->getMimeType();

        $build = [];

        if (str_starts_with($mime, 'image/')) {
            $build['image'] = [
                '#theme' => 'image',
                '#uri' => $absoluteUrl,
                '#attributes' => [
                    'style' => 'max-width: 100%; height: auto;',
                ],
            ];
        } elseif ($mime === 'application/pdf') {
            /** @noinspection HtmlUnknownTarget */
            $build['iframe'] = [
                '#type' => 'inline_template',
                '#template' => '<iframe src="{{ url }}" width="100%" height="600px" style="border: none;"></iframe>',
                '#context' => [
                    'url' => $absoluteUrl,
                ],
            ];
        } else {
            $build['link'] = [
                '#type' => 'link',
                '#title' => $this->t('Click here to view file'),
                '#url' => Url::fromUri($absoluteUrl),
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
    private function getAbsoluteUrl(string $url): string
    {
        if (str_starts_with($url, '/')) {
            return 'base:' . ltrim($url, '/');
        }
        return $url;
    }

    /**
     * Views an application in a modal.
     *
     * @param int $id
     *   The application ID.
     *
     * @return array
     *   A render array suitable for a modal.
     * @throws Exception
     */
    public function viewApplication(int $id): array
    {
        /** @var ApplicationStorage $storage */
        $storage = $this->entityTypeManager()->getStorage('membership_application');

        $application = $storage->load($id);

        if (empty($application)) {
            return [
                '#markup' => $this->t('Application not found.'),
            ];
        }

        $proofURL = null;
        $idURL = null;
        $photoURL = null;

        $hasVerifiedEmail = $application->getValue(ApplicationField::HasVerifiedEmail);
        $hasVerifiedID = $application->getValue(ApplicationField::HasVerifiedID);
        $hasVerifiedStatus = $application->getValue(ApplicationField::HasVerifiedStatus);

        $hasESNcard = $application->getValue(ApplicationField::HasESNcard);

        $fieldData = [];
        foreach (ApplicationField::cases() as $field) {
            if (!$hasESNcard && $field->isESNcardExclusive()) {
                continue;
            }

            $label = $field->label();
            $displayValue = $application->getValue($field) ?? '';

            if (!empty($displayValue)) {
                if ($field == ApplicationField::DateOfBirth) {
                    $displayValue = (new DrupalDateTime($displayValue))->format('d/m/Y');
                }

                if ($field->type() == 'datetime') {
                    $displayValue = (new DrupalDateTime($displayValue))->format('d/m/Y H:i');
                }

                if ($field->type() == 'entity_reference') {
                    if ($url = $this->fileService->getFileURL($displayValue)) {
                        switch ($field) {
                            case ApplicationField::StatusProofFileID:
                                $proofURL = $url;
                                break;
                            case ApplicationField::IdentityDocumentFileID:
                                $idURL = $url;
                                break;
                            case ApplicationField::FacePhotoFileID:
                                $photoURL = $url;
                                break;
                            default:
                                break;
                        }
                        $displayValue = Link::fromTextAndUrl($url, Url::fromUri($url, ['attributes' => ['target' => '_blank']]))->toRenderable();
                    } else {
                        $displayValue = $this->t('File not available');
                    }
                }

                if ($field == ApplicationField::PaymentLink) {
                    $displayValue = Link::fromTextAndUrl($displayValue, Url::fromUri($displayValue, ['attributes' => ['target' => '_blank']]))->toRenderable();
                }
            }

            if ($field->type() == 'boolean') {
                $displayValue = $displayValue == 1 ? 'YES' : 'NO';
            }

            $fieldData[] = [
                'key' => $field->value,
                'label' => $label,
                'value' => $displayValue,
                'readonly' => $field->isReadOnly($hasVerifiedEmail, $hasVerifiedID, $hasVerifiedStatus),
            ];
        }

        $approvalStatus = $application->getValue(ApplicationField::ApprovalStatus);

        return [
            '#theme' => 'emm_application_view',
            '#id' => $id,
            '#fieldData' => $fieldData,
            '#urls' => [
                'proof' => $proofURL,
                'id' => $idURL,
                'photo' => $photoURL,
            ],
            '#permissions' => [
                'edit' => $this->currentUser()->hasPermission('edit applications'),
                'approve' => $this->currentUser()->hasPermission('approve applications'),
                'decline' => $this->currentUser()->hasPermission('decline applications'),
            ],
            '#apiURLs' => [
                'update' => Url::fromRoute('esn_membership_manager.edit')->toString(),
                'crop' => Url::fromRoute('esn_membership_manager.crop')->toString(),
                'status' => Url::fromRoute('esn_membership_manager.status')->toString()
            ],
            '#is_paid' => $approvalStatus == "Paid" || $approvalStatus == "Issued" || $approvalStatus == "Delivered",
        ];
    }

    /**
     * Display a success page for application.
     */
    public function successPage(): array
    {
        return [
            '#markup' => $this->t('<div><h3>Thank you for your application!</h3><p>We have successfully received your details. Please check your email for confirmation.</p><p><a href="/memberships/apply">Submit another application</a></p></div>'),
        ];
    }

    /**
     * Views the ESNcard for an application.
     *
     * @param int $id
     *   The application ID.
     *
     * @return array
     *   A render array.
     * @throws Exception
     */
    public function viewESNcard(int $id): array
    {
        /** @var ApplicationStorage $storage */
        $storage = $this->entityTypeManager()->getStorage('membership_application');

        $application = $storage->load($id);

        if (empty($application)) {
            return [
                '#markup' => $this->t('Application not found.'),
            ];
        }

        $dob = explode('-', $application->getValue(ApplicationField::DateOfBirth));

        $paidDate = $application->getDatePaid();
        $paidDate->setTime(0, 0);
        $validSince = explode('-', $paidDate->format('Y-m-d'));

        $facePhotoURL = $this->fileService->getFileURL(!empty($application->getFacePhoto()) ? $application->getFacePhoto()->id() : null);

        return [
            '#theme' => 'emm_esncard',
            '#face_photo_link' => $facePhotoURL,
            '#full_name' => $application->getFullName(),
            '#nationality' => $application->getValue(ApplicationField::Nationality),
            '#dob_day' => $dob[2],
            '#dob_month' => $dob[1],
            '#dob_year' => substr($dob[0], -2, 2),
            '#host_institution' => $application->getValue(ApplicationField::HostInstitution),
            '#section' => $application->getValue(ApplicationField::Section),
            '#valid_since_day' => $validSince[2],
            '#valid_since_month' => $validSince[1],
            '#valid_since_year' => substr($validSince[0], -2, 2),
            '#esncard_number' => $application->getValue(ApplicationField::ESNcardNumber) ?? '',
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
        $queryParams = $request->query->all();
        $applicationIDs = $queryParams['id'] ?? [];

        if (!is_array($applicationIDs)) {
            $applicationIDs = [$applicationIDs];
        }

        /** @var ApplicationStorage $storage */
        $storage = $this->entityTypeManager()->getStorage('membership_application');

        $applicationIDs = array_filter(array_map('intval', $applicationIDs));

        /** @var ApplicationInterface $applications */
        if (empty($applicationIDs)) {
            $applications = $storage->getUnproducedESNcards();
        } else {
            $applications = $storage->getSelectedESNcards($applicationIDs);
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

        foreach ($applications as $application) {
            $path = $this->fileService->getFileURL(!empty($application->getFacePhoto()) ? $application->getFacePhoto()->id() : null);

            if (!empty($path) && !file_exists($path)) {
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
