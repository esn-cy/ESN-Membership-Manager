<?php

namespace Drupal\esn_membership_manager\Cron;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationField;
use Drupal\esn_membership_manager\Entity\Application\ApplicationInterface;
use Drupal\esn_membership_manager\Entity\Application\ApplicationStorage;
use Drupal\esn_membership_manager\Service\FileService;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GDPRCron
{
    protected EntityTypeManagerInterface $entityTypeManager;
    protected FileService $fileService;
    protected LoggerChannelInterface $logger;

    public function __construct(
        EntityTypeManagerInterface    $entityTypeManager,
        FileService                   $fileService,
        LoggerChannelFactoryInterface $loggerFactory
    )
    {
        $this->entityTypeManager = $entityTypeManager;
        $this->fileService = $fileService;
        $this->logger = $loggerFactory->get('esn_membership_manager');
    }

    public static function create(ContainerInterface $container): self
    {
        /** @var EntityTypeManagerInterface $entityTypeManager */
        $entityTypeManager = $container->get('entity_type.manager');

        /** @var FileService $fileService */
        $fileService = $container->get('esn_membership_manager.file_service');

        /** @var LoggerChannelFactoryInterface $loggerFactory */
        $loggerFactory = $container->get('logger.factory');

        return new static(
            $entityTypeManager,
            $fileService,
            $loggerFactory
        );
    }

    /**
     * Handles GDPR Retention policies:
     * * 1. Proofs & ID Scans: Deleted after 2 weeks (14 days).
     * * 2. PII & Face Photos: Deleted after 1 year (365 days).
     */
    public function execute(): void
    {
        try {
            /** @var ApplicationStorage $storage */
            $storage = $this->entityTypeManager->getStorage('membership_application');

            $results2W = $storage->get2WeekDeletions();
            $results1Y = $storage->get1YearDeletions();
        } catch (Exception $e) {
            $this->logger->error('Database Query Error: There was an issue fetching the applications. @message', ['@message' => $e->getMessage()]);
            return;
        }

        foreach ($results2W as $result) {
            $this->sensitiveFileDeletion($result);
        }

        foreach ($results1Y as $result) {
            $this->anonymization($result);
        }
    }

    private function sensitiveFileDeletion(ApplicationInterface $application): void
    {
        $fileIDs = [];
        if ($application->getStatusDocument()) $fileIDs[ApplicationField::StatusProofFileID->value] = $application->getStatusDocument()->id();
        if ($application->getIDDocument()) $fileIDs[ApplicationField::IdentityDocumentFileID->value] = $application->getIDDocument()->id();

        if (!empty($fileIDs)) {
            foreach ($fileIDs as $fieldName => $fileID) {
                if ($this->fileService->deleteApplicationFile($fileID, $application->id())) {
                    try {
                        $application->setNull(ApplicationField::from($fieldName));
                        $application->save();
                    } catch (Exception $e) {
                        $this->logger->warning('Database Warning: Unable to nullify field @field for application @id. Message: @message', [
                            '@field' => $fieldName,
                            '@id' => $application->id(),
                            '@message' => $e->getMessage()
                        ]);
                    }
                }
            }
        }
    }

    private function anonymization(ApplicationInterface $application): void
    {
        try {
            $application::postDelete($this->entityTypeManager->getStorage('membership_application'), [$application]);

            $application->setValue(ApplicationField::Name, 'Anonymized');
            $application->setValue(ApplicationField::Surname, 'Anonymized');
            $application->setValue(ApplicationField::Email, $application->id() . '@anonymized.email');
            $application->setValue(ApplicationField::DateOfBirth, $application->getDateOfBirth()->format('Y-01-01'));
            $application->setNull(ApplicationField::FacePhotoFileID);
            $application->setNull(ApplicationField::PaymentLink);
            $application->setNull(ApplicationField::PaymentLinkID);
            $application->save();
        } catch (Exception $e) {
            $this->logger->warning('Database Warning: Unable to anonymize application ID: @id. Message: @message', [
                '@id' => $application->id(),
                '@message' => $e->getMessage()
            ]);
        }
    }
}