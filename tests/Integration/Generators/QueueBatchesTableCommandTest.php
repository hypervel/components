<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Queue\Console\BatchesTableCommand;

class QueueBatchesTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        $this->artisan(BatchesTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            'Schema::create(\'job_batches\', function (Blueprint $table) {',
            'Schema::dropIfExists(\'job_batches\');',
        ], 'create_job_batches_table.php');
    }
}
