<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Session\Console\SessionTableCommand;

class SessionTableCommandTest extends TestCase
{
    public function testCreateMakesMigration(): void
    {
        $this->artisan(SessionTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            "Schema::create('sessions', function (Blueprint \$table) {",
            "\$table->string('user_id')->nullable();",
            "\$table->string('auth_provider')->nullable();",
            "\$table->ipAddress('ip_address')->nullable();",
            "\$table->index('user_id')->whereNotNull('user_id');",
            "Schema::dropIfExists('sessions');",
        ], 'create_sessions_table.php');
    }
}
