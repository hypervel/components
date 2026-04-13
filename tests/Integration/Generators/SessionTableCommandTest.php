<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Session\Console\SessionTableCommand;

/**
 * @internal
 * @coversNothing
 */
class SessionTableCommandTest extends TestCase
{
    public function testCreateMakesMigration()
    {
        $this->artisan(SessionTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            "Schema::create('sessions', function (Blueprint \$table) {",
            "Schema::dropIfExists('sessions');",
        ], 'create_sessions_table.php');
    }
}
