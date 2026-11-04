<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// PHPStan boots the analyzed application once per process when a test first loads
// the extension's bootstrap file. Booting it before any test runs keeps its error
// and exception handlers out of PHPUnit's per-test handler checks.
require_once __DIR__ . '/../../src/phpstan/bootstrap.php';
