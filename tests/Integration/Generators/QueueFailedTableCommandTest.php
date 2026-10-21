<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Queue\Console\FailedTableCommand;

class QueueFailedTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        $this->artisan(FailedTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            "Schema::create('failed_jobs', function (Blueprint \$table) {",
            "\$table->string('uuid')->unique();",
            "\$table->string('connection');",
            "\$table->string('queue');",
            "\$table->longText('payload');",
            "\$table->index(['connection', 'queue', 'failed_at']);",
            "Schema::dropIfExists('failed_jobs');",
        ], 'create_failed_jobs_table.php');
    }
}
