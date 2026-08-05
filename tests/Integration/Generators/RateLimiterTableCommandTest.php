<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use Hypervel\RateLimiter\Console\RateLimiterTableCommand;
use Hypervel\Support\Facades\Date;

class RateLimiterTableCommandTest extends TestCase
{
    public function testCreateMakesTheConfiguredRateLimiterMigration(): void
    {
        Date::setTestNow('2026-08-04 12:00:00');

        try {
            $this->artisan(RateLimiterTableCommand::class)->assertExitCode(0);

            $this->assertMigrationFileContains([
                'use Hypervel\Database\Migrations\Migration;',
                'return new class extends Migration',
                "Schema::create('rate_limits', function (Blueprint \$table) {",
                "\$table->char('key', 32)->primary();",
                "\$table->unsignedBigInteger('value')->default(0);",
                "\$table->unsignedBigInteger('available_at')->default(0);",
                "\$table->unsignedBigInteger('expires_at')->index();",
                "Schema::dropIfExists('rate_limits');",
            ], 'create_rate_limits_table.php');
        } finally {
            Date::setTestNow();
        }
    }

    public function testCreateUsesTheConfiguredTableName(): void
    {
        config(['rate-limiter.stores.database.table' => 'custom_rate_limits']);

        $this->artisan(RateLimiterTableCommand::class)->assertExitCode(0);

        $this->assertMigrationFileContains([
            "Schema::create('custom_rate_limits', function (Blueprint \$table) {",
            "Schema::dropIfExists('custom_rate_limits');",
        ], 'create_custom_rate_limits_table.php');
    }
}
