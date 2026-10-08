<?php

declare(strict_types=1);

namespace Drupal\Tests\esn_membership_manager\Unit\Utility;

use Drupal\Core\Extension\Extension;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\esn_membership_manager\Utility\Nationalities;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Nationalities utility.
 *
 * @covers \Drupal\esn_membership_manager\Utility\Nationalities
 * @group esn_membership_manager
 */
class NationalitiesTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/nationalities_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Tests loading standard nationalities from the module CSV.
     */
    public function testGetStandardNationalities(): void
    {
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn(__DIR__ . '/../../../../');

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->expects($this->once())
            ->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($module);

        $nationalitiesService = new Nationalities($moduleHandler);

        $list = $nationalitiesService->get();

        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
        $this->assertArrayHasKey('Cypriot', $list);
        $this->assertSame('Cypriot', $list['Cypriot']);
    }

    /**
     * Tests loading ISO-keyed nationalities from the module CSV.
     */
    public function testGetIsoNationalities(): void
    {
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn(__DIR__ . '/../../../../');

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->expects($this->once())
            ->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($module);

        $nationalitiesService = new Nationalities($moduleHandler);

        $list = $nationalitiesService->get(true);

        $this->assertIsArray($list);
        $this->assertNotEmpty($list);
        $this->assertArrayHasKey('CYP', $list);
        $this->assertSame('Cypriot', $list['CYP']);
    }

    /**
     * Tests in-memory caching so the CSV file is only read once per mode.
     */
    public function testMemoryCaching(): void
    {
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn(__DIR__ . '/../../../../');

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        // Only called once for false, and once for true
        $moduleHandler->expects($this->exactly(2))
            ->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($module);

        $nationalitiesService = new Nationalities($moduleHandler);

        // First call: reads CSV
        $firstStandard = $nationalitiesService->get();
        // Second call: cached
        $secondStandard = $nationalitiesService->get();
        $this->assertSame($firstStandard, $secondStandard);

        // First call with ISO: reads CSV
        $firstIso = $nationalitiesService->get(true);
        // Second call with ISO: cached
        $secondIso = $nationalitiesService->get(true);
        $this->assertSame($firstIso, $secondIso);
    }

    /**
     * Tests that an Exception from getModule() is caught and returns empty array.
     */
    public function testExceptionHandlingWhenModuleNotFound(): void
    {
        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')
            ->with('esn_membership_manager')
            ->willThrowException(new Exception('Module not found'));

        $nationalitiesService = new Nationalities($moduleHandler);

        $result = $nationalitiesService->get();
        $this->assertSame([], $result);

        $resultIso = $nationalitiesService->get(true);
        $this->assertSame([], $resultIso);
    }

    /**
     * Tests that a non-existent CSV file returns an empty array.
     */
    public function testFileDoesNotExistReturnsEmptyArray(): void
    {
        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn('/non/existent/path');

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')
            ->with('esn_membership_manager')
            ->willReturn($module);

        $nationalitiesService = new Nationalities($moduleHandler);

        $this->assertSame([], $nationalitiesService->get());
    }

    /**
     * Tests that empty/blank rows in the CSV are properly skipped.
     */
    public function testCsvRowsWithEmptyValuesAreSkipped(): void
    {
        mkdir($this->tempDir . '/assets/data', 0777, true);
        $csvPath = $this->tempDir . '/assets/data/nationalities.csv';

        // CSV content with valid and invalid rows
        $csvContent = implode("\n", [
            'GRC,Greek',
            ',MissingIso',
            'FRA,',
            ',',
            'DEU,German',
        ]);
        file_put_contents($csvPath, $csvContent);

        $module = $this->createMock(Extension::class);
        $module->method('getPath')->willReturn($this->tempDir);

        $moduleHandler = $this->createMock(ModuleHandlerInterface::class);
        $moduleHandler->method('getModule')->willReturn($module);

        // Standard (requires value in column 1)
        $service1 = new Nationalities($moduleHandler);
        $standard = $service1->get();
        $this->assertArrayHasKey('Greek', $standard);
        $this->assertArrayHasKey('MissingIso', $standard);
        $this->assertArrayHasKey('German', $standard);
        $this->assertCount(3, $standard);

        // ISO (requires values in both column 0 and column 1)
        $service2 = new Nationalities($moduleHandler);
        $iso = $service2->get(true);
        $this->assertArrayHasKey('GRC', $iso);
        $this->assertArrayHasKey('DEU', $iso);
        $this->assertCount(2, $iso);
    }
}
