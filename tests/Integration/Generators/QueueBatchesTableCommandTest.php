<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Queue\Console\BatchesTableCommand;

class QueueBatchesTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        config([
            'queue.batching.table' => 'imports',
            'queue.batching.results_table' => 'import_results',
            'queue.batching.deletions_table' => 'import_deletions',
        ]);
        $this->artisan(BatchesTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            "Schema::create('imports',",
            "\$table->integer('outstanding_failed_jobs');",
            "Schema::dropIfExists('imports');",
        ], 'create_imports_table.php');

        $this->assertMigrationFileContains([
            "Schema::create('import_results',",
            "\$table->primary(['batch_id', 'job_id_hash']);",
            "Schema::dropIfExists('import_results');",
        ], 'create_import_results_table.php');

        $this->assertMigrationFileContains([
            "Schema::create('import_deletions',",
            "\$table->string('batch_id')->primary();",
            "Schema::dropIfExists('import_deletions');",
        ], 'create_import_deletions_table.php');

        $this->assertLessThan(
            $this->findFirstPublishedMigrationFile('create_import_results_table.php'),
            $this->findFirstPublishedMigrationFile('create_imports_table.php'),
        );
        $this->artisan('queue:batches-table')->expectsOutputToContain('Migrations already exist.')->assertExitCode(1);
    }

    public function testBundledJobsMigrationNeedsOnlyTheResultAndDeletionMigrations(): void
    {
        $this->app->make('files')->put(database_path('migrations/0001_01_01_000002_create_jobs_table.php'), '<?php');

        $this->artisan(BatchesTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileDoesNotExists('create_job_batches_table.php');
        $this->assertMigrationFileExists('create_job_batch_results_table.php');
        $this->assertMigrationFileExists('create_job_batch_deletions_table.php');
    }
}
