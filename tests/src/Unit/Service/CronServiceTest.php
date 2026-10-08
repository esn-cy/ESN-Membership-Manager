<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\Application;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\CronService;
use Drupal\esn_membership_manager\Service\FileService;
use Drupal\file\FileInterface;
use Drupal\omnia\Entity\FieldEnumInterface;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;
use Exception;

/**
 * Test application double for GDPRCron anonymization testing.
 */
class TestApplicationForCron extends Application
{
    public array $testValues = [];
    public array $nullFields = [];
    public bool $saved = false;
    public ?DrupalDateTime $dob = null;
    public static bool $postDeleteCalled = false;

    public function __construct()
    {
    }

    public static function postDelete(EntityStorageInterface $storage, array $entities): void
    {
        self::$postDeleteCalled = true;
    }

    public function id(): string
    {
        return '102';
    }

    public function setValue(FieldEnumInterface $field, mixed $value): static
    {
        $this->testValues[$field->value] = $value;
        return $this;
    }

    public function setNull(FieldEnumInterface $field): static
    {
        $this->nullFields[] = $field->value;
        return $this;
    }

    public function getDateOfBirth(): DrupalDateTime
    {
        return $this->dob;
    }

    public function save(): void
    {
        $this->saved = true;
    }
}

/**
 * Tests for CronService and GDPRCron.
 *
 * @covers       \Drupal\esn_membership_manager\Service\CronService
 * @covers       \Drupal\esn_membership_manager\Cron\GDPRCron
 * @uses         \Drupal\esn_membership_manager\Entity\Application\Application
 * @group esn_membership_manager
 * @noinspection PhpUnnecessaryFullyQualifiedNameInspection
 */
class CronServiceTest extends MembershipManagerTestCase
{
    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testExecuteProcessesDeletionsAndAnonymizations(): void
    {
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $fileService = $this->createMock(FileService::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($applicationStorage);
        $loggerFactory = $this->getLoggerFactoryMock();

        // 2-week deletion mock
        $app2W = $this->createMock(ApplicationInterface::class);
        $app2W->method('id')->willReturn('101');
        $statusDoc = $this->createMock(FileInterface::class);
        $statusDoc->method('id')->willReturn('201');
        $idDoc = $this->createMock(FileInterface::class);
        $idDoc->method('id')->willReturn('202');

        $app2W->method('getStatusDocument')->willReturn($statusDoc);
        $app2W->method('getIDDocument')->willReturn($idDoc);

        $fileService->expects($this->exactly(2))
            ->method('deleteApplicationFile')
            ->willReturnMap([
                ['201', '101', true],
                ['202', '101', true],
            ]);

        $matcher = $this->exactly(2);
        $app2W->expects($matcher)
            ->method('setNull')
            ->willReturnCallback(function ($field) use ($matcher, $app2W) {
                match ($matcher->getInvocationCount()) {
                    1 => $this->assertSame(ApplicationField::StatusProofFileID, $field),
                    2 => $this->assertSame(ApplicationField::IdentityDocumentFileID, $field),
                };
                return $app2W;
            });
        $app2W->expects($this->exactly(2))->method('save');

        // 1-year anonymization test double
        $app1Y = new TestApplicationForCron();
        $app1Y->dob = new DrupalDateTime('2000-05-15');

        $applicationStorage->expects($this->once())
            ->method('get2WeekDeletions')
            ->willReturn([$app2W]);

        $applicationStorage->expects($this->once())
            ->method('get1YearDeletions')
            ->willReturn([$app1Y]);

        $cronService = new CronService($entityTypeManager, $fileService, $loggerFactory);
        $cronService->execute();

        $this->assertTrue(TestApplicationForCron::$postDeleteCalled);
        $this->assertEquals('Anonymized', $app1Y->testValues[ApplicationField::Name->value]);
        $this->assertEquals('Anonymized', $app1Y->testValues[ApplicationField::Surname->value]);
        $this->assertEquals('102@anonymized.email', $app1Y->testValues[ApplicationField::Email->value]);
        $this->assertEquals('2000-01-01', $app1Y->testValues[ApplicationField::DateOfBirth->value]);
        $this->assertContains(ApplicationField::FacePhotoFileID->value, $app1Y->nullFields);
        $this->assertContains(ApplicationField::PaymentLink->value, $app1Y->nullFields);
        $this->assertContains(ApplicationField::PaymentLinkID->value, $app1Y->nullFields);
        $this->assertTrue($app1Y->saved);
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testExecuteWithEmptyResults(): void
    {
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $fileService = $this->createMock(FileService::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($applicationStorage);
        $loggerFactory = $this->getLoggerFactoryMock();

        $applicationStorage->expects($this->once())
            ->method('get2WeekDeletions')
            ->willReturn([]);

        $applicationStorage->expects($this->once())
            ->method('get1YearDeletions')
            ->willReturn([]);

        $fileService->expects($this->never())->method('deleteApplicationFile');

        $cronService = new CronService($entityTypeManager, $fileService, $loggerFactory);
        $cronService->execute();
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testSensitiveFileDeletionHandlesSaveException(): void
    {
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $fileService = $this->createMock(FileService::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($applicationStorage);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Unable to nullify field'));
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $app = $this->createMock(ApplicationInterface::class);
        $app->method('id')->willReturn('103');
        $statusDoc = $this->createMock(FileInterface::class);
        $statusDoc->method('id')->willReturn('301');
        $app->method('getStatusDocument')->willReturn($statusDoc);
        $app->method('getIDDocument')->willReturn(null);

        $fileService->method('deleteApplicationFile')->willReturn(true);
        $app->method('save')->willThrowException(new Exception('Database locked'));

        $applicationStorage->method('get2WeekDeletions')->willReturn([$app]);
        $applicationStorage->method('get1YearDeletions')->willReturn([]);

        $cronService = new CronService($entityTypeManager, $fileService, $loggerFactory);
        $cronService->execute();
    }

    /**
     * @throws InvalidPluginDefinitionException
     * @throws PluginNotFoundException
     */
    public function testAnonymizationHandlesException(): void
    {
        $applicationStorage = $this->createMock(ApplicationStorage::class);
        $fileService = $this->createMock(FileService::class);
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getStorage')
            ->with('membership_application')
            ->willReturn($applicationStorage);

        $logger = $this->createMock(LoggerChannelInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('Unable to anonymize application ID'));
        $loggerFactory = $this->getLoggerFactoryMock($logger);

        $app = new TestApplicationForCron();
        $app->dob = new DrupalDateTime('2000-01-01');
        // Override save to throw
        $appThrowing = new class extends TestApplicationForCron {
            public function save(): void
            {
                throw new Exception('Failed to anonymize');
            }
        };
        $appThrowing->dob = new DrupalDateTime('2000-01-01');

        $applicationStorage->method('get2WeekDeletions')->willReturn([]);
        $applicationStorage->method('get1YearDeletions')->willReturn([$appThrowing]);

        $cronService = new CronService($entityTypeManager, $fileService, $loggerFactory);
        $cronService->execute();
    }
}
