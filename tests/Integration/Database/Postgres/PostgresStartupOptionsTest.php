<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Verify startup values through libpq's connection-string parser and the
 * server's options parser; DSN-only assertions cannot catch lost escaping.
 */
#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class PostgresStartupOptionsTest extends PostgresTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $base = $config->array('database.connections.pgsql');

        $config->set('database.connections.pgsql_startup_combined', array_merge($base, [
            'search_path' => ['public', "team's reports", 'team\reports', 'team"reports'],
            'application_name' => "team's\\app",
            'server_options' => [
                'app.label' => "team's reports\\daily",
                'TimeZone' => 'Asia/Tokyo',
                'application_name' => 'fallback',
            ],
            'timezone' => 'UTC',
            'isolation_level' => 'read committed',
            'synchronous_commit' => 'off',
        ]));
    }

    public function testCombinedStartupOptionsAllSurviveDsnTransit(): void
    {
        $connection = DB::connection('pgsql_startup_combined');

        $this->assertSame(
            '"public", "team\'s reports", "team\reports", "team""reports"',
            $connection->selectOne('SHOW search_path')->search_path,
        );
        $this->assertSame("team's reports\\daily", $connection->selectOne('SHOW app.label')->{'app.label'});
        $this->assertSame("team's\\app", $connection->selectOne('SHOW application_name')->application_name);
        $this->assertSame(
            'UTC',
            $connection->selectOne('SHOW TimeZone')->TimeZone,
        );
        $this->assertSame(
            'read committed',
            $connection->selectOne('SHOW default_transaction_isolation')->default_transaction_isolation,
        );
        $this->assertSame(
            'off',
            $connection->selectOne('SHOW synchronous_commit')->synchronous_commit,
        );
    }
}
