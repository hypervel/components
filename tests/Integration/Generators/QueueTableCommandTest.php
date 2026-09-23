<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Queue\Console\TableCommand;

class QueueTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        $this->artisan(TableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            'Schema::create(\'jobs\', function (Blueprint $table) {',
            'Schema::dropIfExists(\'jobs\');',
        ], 'create_jobs_table.php');
    }
}
