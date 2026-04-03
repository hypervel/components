<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\Notifications\Console\NotificationTableCommand;

/**
 * @internal
 * @coversNothing
 */
class NotificationTableCommandTest extends TestCase
{
    public function testCreateMakesMigration()
    {
        $this->artisan(NotificationTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            'use Hypervel\Database\Migrations\Migration;',
            'return new class extends Migration',
            "Schema::create('notifications', function (Blueprint \$table) {",
            "Schema::dropIfExists('notifications');",
        ], 'create_notifications_table.php');
    }
}
