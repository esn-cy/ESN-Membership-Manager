<?php

/**
 * @file
 * Test bootstrap for PHPUnit tests in ESN Membership Manager.
 */

$loader = require __DIR__ . '/../vendor/autoload.php';

// Set default timezone.
date_default_timezone_set('UTC');

// Silence TCPDF 6 deprecation notice.
if (!defined('TCPDF_SILENCE_DEPRECATION')) {
    define('TCPDF_SILENCE_DEPRECATION', true);
}

// Register Drupal core modules in autoloader (e.g. Drupal\file\).
$coreModulesDir = __DIR__ . '/../vendor/drupal/core/modules';
if (is_dir($coreModulesDir)) {
    foreach (scandir($coreModulesDir) as $module) {
        if ($module[0] === '.') {
            continue;
        }
        $srcDir = "$coreModulesDir/$module/src";
        if (is_dir($srcDir)) {
            $loader->addPsr4("Drupal\\$module\\", $srcDir);
        }
    }
}

// Ensure PHPUnit compatibility trait is available for UnitTestCase in PHPUnit 9.
if (!trait_exists('Drupal\Tests\PhpUnitCompatibilityTrait', false)) {
    class_alias('Drupal\TestTools\PhpUnitCompatibility\PhpUnit11\TestCompatibilityTrait', 'Drupal\Tests\PhpUnitCompatibilityTrait');
}

// Load test function overrides.
require_once __DIR__ . '/fixtures/random_int_override.php';

