<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Service;

use Drupal\esn_membership_manager\Service\FileService;
use Drupal\Tests\esn_membership_manager\Unit\MembershipManagerTestCase;

/**
 * Tests for FileService.
 *
 * @covers \Drupal\esn_membership_manager\Service\FileService
 * @group esn_membership_manager
 */
class FileServiceTest extends MembershipManagerTestCase
{
    public function testCreateApplicationFileWithApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('createFile')
            ->with(
                'raw-file-data',
                'membership://proofs',
                'proof_123',
                'esn_membership_manager',
                ['membership_application' => 123]
            )
            ->willReturn('42');

        $result = $fileService->createApplicationFile('raw-file-data', 'membership://proofs', 'proof_123', 123);
        $this->assertEquals('42', $result);
    }

    public function testCreateApplicationFileWithoutApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('createFile')
            ->with(
                'raw-file-data',
                'membership://proofs',
                'proof_temp',
                'esn_membership_manager',
                []
            )
            ->willReturn(null);

        $result = $fileService->createApplicationFile('raw-file-data', 'membership://proofs', 'proof_temp', null);
        $this->assertNull($result);
    }

    public function testSaveApplicationFileWithApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['saveFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('saveFile')
            ->with(
                42,
                'esn_membership_manager',
                ['membership_application' => 'app-99']
            )
            ->willReturn(true);

        $result = $fileService->saveApplicationFile(42, 'app-99');
        $this->assertTrue($result);
    }

    public function testSaveApplicationFileWithoutApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['saveFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('saveFile')
            ->with(
                42,
                'esn_membership_manager',
                []
            )
            ->willReturn(false);

        $result = $fileService->saveApplicationFile(42, null);
        $this->assertFalse($result);
    }

    public function testDeleteApplicationFileWithApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('deleteFile')
            ->with(
                42,
                'esn_membership_manager',
                ['membership_application' => 555]
            )
            ->willReturn(true);

        $result = $fileService->deleteApplicationFile(42, 555);
        $this->assertTrue($result);
    }

    public function testDeleteApplicationFileWithoutApplicationId(): void
    {
        $fileService = $this->getMockBuilder(FileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['deleteFile'])
            ->getMock();

        $fileService->expects($this->once())
            ->method('deleteFile')
            ->with(
                42,
                'esn_membership_manager',
                []
            )
            ->willReturn(false);

        $result = $fileService->deleteApplicationFile(42, null);
        $this->assertFalse($result);
    }
}
