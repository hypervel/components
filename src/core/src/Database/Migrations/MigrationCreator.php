<?php

declare(strict_types=1);

namespace Hypervel\Database\Migrations;

use Hyperf\Database\Migrations\MigrationCreator as HyperfMigrationCreator;

class MigrationCreator extends HyperfMigrationCreator
{
    /**
     * Get the path to the stubs.
     */
    public function stubPath(): string
    {
        return __DIR__ . '/stubs';
    }
}
