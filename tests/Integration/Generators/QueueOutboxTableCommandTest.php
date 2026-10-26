<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Queue\Console\OutboxTableCommand;

class QueueOutboxTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        config(['queue.outbox.table' => 'outgoing_jobs']);
        $this->artisan(OutboxTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            'Schema::create(\'outgoing_jobs\', function (Blueprint $table) {',
            '$table->uuid(\'uuid\')->unique();',
            '$table->longText(\'payload\');',
            '$table->unsignedBigInteger(\'deliver_at\')->nullable();',
            '$table->index([\'available_at\', \'id\']);',
            'Schema::dropIfExists(\'outgoing_jobs\');',
        ], 'create_outgoing_jobs_table.php');

        $this->artisan('queue:outbox-table')->assertExitCode(1);
    }
}
