<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Controller;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\esn_membership_manager\Controller\ApplicationController;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\file\FileInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @covers       \Drupal\esn_membership_manager\Controller\ApplicationController
 * @uses         \Drupal\esn_membership_manager\Entity\Application\Application
 * @uses         \Drupal\esn_membership_manager\Entity\Application\ApplicationField
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class ApplicationControllerTest extends MembershipManagerTestCase
{
    private EntityTypeManagerInterface $entityTypeManager;
    private ApplicationStorage $applicationStorage;
    private FileService $fileService;
    private CsrfTokenGenerator $csrfTokenGenerator;
    private AccountProxyInterface $currentUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationStorage = $this->createMock(ApplicationStorage::class);
        $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $this->entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($this->applicationStorage);

        $this->fileService = $this->createMock(FileService::class);
        $this->csrfTokenGenerator = $this->createMock(CsrfTokenGenerator::class);

        $this->currentUser = $this->createMock(AccountProxyInterface::class);
        $this->container->set('current_user', $this->currentUser);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generateFromRoute')
            ->willReturnCallback(fn($name) => '/dummy/' . $name);
        $this->container->set('url_generator', $urlGenerator);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testCreate(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')
            ->willReturnCallback(function ($id) {
                return match ($id) {
                    'entity_type.manager' => $this->entityTypeManager,
                    'esn_membership_manager.file_service' => $this->fileService,
                    'csrf_token' => $this->csrfTokenGenerator,
                    default => null,
                };
            });

        $controller = ApplicationController::create($container);
        $this->assertInstanceOf(ApplicationController::class, $controller);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPreviewImage(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('createFileUrl')->with(false)->willReturn('/sites/default/files/photo.png');
        $file->method('getMimeType')->willReturn('image/png');

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->preview($file);
        $this->assertArrayHasKey('image', $build);
        $this->assertEquals('image', $build['image']['#theme']);
        $this->assertEquals('base:sites/default/files/photo.png', $build['image']['#uri']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPreviewPdf(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('createFileUrl')->with(false)->willReturn('https://example.com/doc.pdf');
        $file->method('getMimeType')->willReturn('application/pdf');

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->preview($file);
        $this->assertArrayHasKey('iframe', $build);
        $this->assertEquals('inline_template', $build['iframe']['#type']);
        $this->assertEquals('https://example.com/doc.pdf', $build['iframe']['#context']['url']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testPreviewOtherFileType(): void
    {
        $file = $this->createMock(FileInterface::class);
        $file->method('createFileUrl')->with(false)->willReturn('https://example.com/file.docx');
        $file->method('getMimeType')->willReturn('application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->preview($file);
        $this->assertArrayHasKey('link', $build);
        $this->assertArrayHasKey('message', $build);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewApplicationNotFound(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(123)
            ->willReturn(null);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewApplication(123);
        $this->assertArrayHasKey('#markup', $build);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewApplicationFound(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::HasVerifiedEmail, ApplicationField::HasVerifiedID, ApplicationField::HasVerifiedStatus, ApplicationField::HasESNcard => true,
                ApplicationField::DateOfBirth => '2001-05-15',
                ApplicationField::PaymentLink => 'https://example.com/payment',
                default => 'TestValue',
            };
        });
        $app->method('isPaid')->willReturn(true);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(123)
            ->willReturn($app);

        $this->fileService->method('getFileURL')->willReturn('https://example.com/file.png');
        $this->csrfTokenGenerator->method('get')->with('rest')->willReturn('csrf_token_123');
        $this->currentUser->method('hasPermission')->willReturn(true);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewApplication(123);
        $this->assertEquals('emm_application_view', $build['#theme']);
        $this->assertEquals(123, $build['#id']);
        $this->assertEquals('csrf_token_123', $build['#csrf_token']);
        $this->assertTrue($build['#is_paid']);
        $this->assertEquals('https://example.com/file.png', $build['#urls']['proof']);
        $this->assertEquals('https://example.com/file.png', $build['#urls']['id']);
        $this->assertEquals('https://example.com/file.png', $build['#urls']['photo']);
        $this->assertArrayHasKey('#fieldData', $build);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewApplicationWithUnavailableFiles(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::HasVerifiedEmail, ApplicationField::HasVerifiedID, ApplicationField::HasVerifiedStatus, ApplicationField::HasESNcard => true,
                ApplicationField::DateOfBirth => '2001-05-15',
                ApplicationField::PaymentLink => 'https://example.com/payment',
                default => 'TestValue',
            };
        });
        $app->method('isPaid')->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(456)
            ->willReturn($app);

        $this->fileService->method('getFileURL')->willReturn(null);
        $this->csrfTokenGenerator->method('get')->with('rest')->willReturn('csrf_token_456');
        $this->currentUser->method('hasPermission')->willReturn(true);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewApplication(456);
        $this->assertEquals('emm_application_view', $build['#theme']);
        $this->assertEquals(456, $build['#id']);
        $this->assertNull($build['#urls']['proof']);
        $this->assertNull($build['#urls']['id']);
        $this->assertNull($build['#urls']['photo']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testSuccessPage(): void
    {
        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->successPage();
        $this->assertArrayHasKey('#markup', $build);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewESNcardNotFound(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(123)
            ->willReturn(null);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewESNcard(123);
        $this->assertArrayHasKey('#markup', $build);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewESNcardFound(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::DateOfBirth => '2001-05-15',
                ApplicationField::Section => 'ESN Nicosia',
                ApplicationField::Nationality => 'Cypriot',
                ApplicationField::HostInstitution => 'UCY',
                ApplicationField::ESNcardNumber => '1234567ABCD',
                default => null,
            };
        });
        $app->method('getDatePaid')->willReturn(new DrupalDateTime('2026-04-01'));
        $app->method('getFullName')->willReturn('John Doe');
        $app->method('getFacePhoto')->willReturn(null);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(123)
            ->willReturn($app);

        $this->fileService->method('getFileURL')->willReturn('https://example.com/photo.jpg');

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewESNcard(123);
        $this->assertEquals('emm_esncard', $build['#theme']);
        $this->assertEquals('John Doe', $build['#full_name']);
        $this->assertEquals('Nicosia', $build['#section']);
        $this->assertEquals('15', $build['#dob_day']);
        $this->assertEquals('05', $build['#dob_month']);
        $this->assertEquals('01', $build['#dob_year']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithEmptyApplications(): void
    {
        $this->applicationStorage->expects($this->once())
            ->method('getUnproducedESNcards')
            ->willReturn([]);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $request = new Request();
        $response = $controller->generateFacePDF($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('esncard_images.pdf', (string)$response->headers->get('Content-Disposition'));
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithApplicationsAndImages(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_face_') . '.png';
        $im = imagecreatetruecolor(100, 100);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 0, 128, 255));
        imagepng($im, $tmpFile);

        try {
            $photo = $this->createMock(FileInterface::class);
            $photo->method('id')->willReturn('88');

            $app = $this->createMock(ApplicationInterface::class);
            $app->method('getFacePhoto')->willReturn($photo);

            $this->applicationStorage->expects($this->once())
                ->method('getSelectedESNcards')
                ->with([42])
                ->willReturn([$app]);

            $this->fileService->expects($this->once())
                ->method('getFilePath')
                ->with('88')
                ->willReturn($tmpFile);

            $controller = new ApplicationController(
                $this->entityTypeManager,
                $this->fileService,
                $this->csrfTokenGenerator
            );

            $request = new Request(['id' => '42']);
            $response = $controller->generateFacePDF($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithCorruptedOrInvalidImage(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_corrupt_') . '.jpg';
        file_put_contents($tmpFile, 'not-a-valid-image');

        try {
            $photo = $this->createMock(FileInterface::class);
            $photo->method('id')->willReturn('99');

            $app = $this->createMock(ApplicationInterface::class);
            $app->method('id')->willReturn('998');
            $app->method('getFacePhoto')->willReturn($photo);

            $this->applicationStorage->expects($this->once())
                ->method('getSelectedESNcards')
                ->with([998])
                ->willReturn([$app]);

            $this->fileService->expects($this->once())
                ->method('getFilePath')
                ->with('99')
                ->willReturn($tmpFile);

            $controller = new ApplicationController(
                $this->entityTypeManager,
                $this->fileService,
                $this->csrfTokenGenerator
            );

            $request = new Request(['id' => '998']);
            $response = $controller->generateFacePDF($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithMissingOrNonExistentPhoto(): void
    {
        $app1 = $this->createMock(ApplicationInterface::class);
        $app1->method('getFacePhoto')->willReturn(null);

        $photo2 = $this->createMock(FileInterface::class);
        $photo2->method('id')->willReturn('202');

        $app2 = $this->createMock(ApplicationInterface::class);
        $app2->method('getFacePhoto')->willReturn($photo2);

        $this->applicationStorage->expects($this->once())
            ->method('getSelectedESNcards')
            ->with([1, 2])
            ->willReturn([$app1, $app2]);

        $this->fileService->expects($this->exactly(2))
            ->method('getFilePath')
            ->willReturnMap([
                [null, null],
                ['202', '/non/existent/path.jpg'],
            ]);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $request = new Request(['id' => [1, 2]]);
        $response = $controller->generateFacePDF($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->getContent());
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testViewApplicationWithFreePassAndReferencedFilesAndUndeterminedNationality(): void
    {
        $app = $this->createMock(ApplicationInterface::class);
        $app->method('getValue')->willReturnCallback(function ($field) {
            return match ($field) {
                ApplicationField::HasVerifiedEmail, ApplicationField::HasVerifiedID, ApplicationField::HasVerifiedStatus, ApplicationField::HasESNcard => false,
                ApplicationField::Nationality => 'Undetermined',
                ApplicationField::StatusProofFileID => '10',
                ApplicationField::IdentityDocumentFileID => '20',
                ApplicationField::FacePhotoFileID => '30',
                ApplicationField::DateOfBirth => '2001-05-15',
                ApplicationField::PaymentLink => 'https://example.com/payment',
                default => 'TestVal',
            };
        });
        $app->method('isPaid')->willReturn(false);

        $this->applicationStorage->expects($this->once())
            ->method('load')
            ->with(456)
            ->willReturn($app);

        $this->fileService->method('getFileURL')
            ->willReturnCallback(fn($fid) => $fid ? "https://example.com/file/$fid.pdf" : null);

        $this->csrfTokenGenerator->method('get')->with('rest')->willReturn('csrf_token_456');
        $this->currentUser->method('hasPermission')->willReturn(false);

        $controller = new ApplicationController(
            $this->entityTypeManager,
            $this->fileService,
            $this->csrfTokenGenerator
        );

        $build = $controller->viewApplication(456);
        $this->assertEquals('emm_application_view', $build['#theme']);
        $this->assertEquals(456, $build['#id']);
        $this->assertFalse($build['#is_paid']);
        $this->assertEquals('https://example.com/file/10.pdf', $build['#urls']['proof']);
        $this->assertEquals('https://example.com/file/20.pdf', $build['#urls']['id']);
        $this->assertNull($build['#urls']['photo']);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithMultiPageImageGrid(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_grid_') . '.png';
        $im = imagecreatetruecolor(100, 100);
        imagefill($im, 0, 0, (int)imagecolorallocate($im, 0, 200, 100));
        imagepng($im, $tmpFile);

        try {
            $apps = [];
            for ($i = 1; $i <= 50; $i++) {
                $photo = $this->createMock(FileInterface::class);
                $photo->method('id')->willReturn((string)$i);

                $app = $this->createMock(ApplicationInterface::class);
                $app->method('getFacePhoto')->willReturn($photo);
                $apps[] = $app;
            }

            $this->applicationStorage->expects($this->once())
                ->method('getUnproducedESNcards')
                ->willReturn($apps);

            $this->fileService->expects($this->exactly(50))
                ->method('getFilePath')
                ->willReturn($tmpFile);

            $controller = new ApplicationController(
                $this->entityTypeManager,
                $this->fileService,
                $this->csrfTokenGenerator
            );

            $request = new Request();
            $response = $controller->generateFacePDF($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithZeroDimensionImage(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_zero_') . '.gif';
        // GIF with 0 width and 0 height
        file_put_contents($tmpFile, "GIF89a\x00\x00\x00\x00\x80\x00\x00");

        try {
            $photo = $this->createMock(FileInterface::class);
            $photo->method('id')->willReturn('77');

            $app = $this->createMock(ApplicationInterface::class);
            $app->method('getFacePhoto')->willReturn($photo);

            $this->applicationStorage->expects($this->once())
                ->method('getUnproducedESNcards')
                ->willReturn([$app]);

            $this->fileService->expects($this->once())
                ->method('getFilePath')
                ->with('77')
                ->willReturn($tmpFile);

            $controller = new ApplicationController(
                $this->entityTypeManager,
                $this->fileService,
                $this->csrfTokenGenerator
            );

            $request = new Request();
            $response = $controller->generateFacePDF($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     * @throws Exception
     */
    public function testGenerateFacePDFWithCorruptImageSkipsGracefully(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'corrupt_') . '.png';
        $ihdrData = "IHDR" . pack("NN", 10, 10) . "\x08\x06\x00\x00\x00";
        $crc = pack("N", crc32($ihdrData));
        $png = "\x89PNG\r\n\x1a\n" . pack("N", 13) . $ihdrData . $crc;
        file_put_contents($tmpFile, $png);

        try {
            $photo = $this->createMock(FileInterface::class);
            $photo->method('id')->willReturn('88');

            $app = $this->createMock(ApplicationInterface::class);
            $app->method('getFacePhoto')->willReturn($photo);

            $this->applicationStorage->expects($this->once())
                ->method('getUnproducedESNcards')
                ->willReturn([$app]);

            $this->fileService->expects($this->once())
                ->method('getFilePath')
                ->with('88')
                ->willReturn($tmpFile);

            $controller = new ApplicationController(
                $this->entityTypeManager,
                $this->fileService,
                $this->csrfTokenGenerator
            );

            $request = new Request();
            $response = $controller->generateFacePDF($request);

            $this->assertEquals(200, $response->getStatusCode());
            $this->assertEquals('application/pdf', $response->headers->get('Content-Type'));
            $this->assertNotEmpty($response->getContent());
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }
}


