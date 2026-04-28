<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Postgres;

use Hypervel\Support\Facades\DB;
use PHPUnit\Framework\Attributes\RequiresOperatingSystem;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * Proves that startup parameters baked into the DSN via libpq's "options"
 * parameter actually land on the backend with their intended values.
 *
 * The unit tests in DatabaseConnectorTest only assert on the DSN string
 * that PostgresConnector generates — they don't exercise PDO's DSN parser
 * or libpq, so they cannot catch escape-passthrough regressions. PDO
 * consumes one level of backslash escaping when extracting a single-quoted
 * value from the DSN, so a single backslash before a space in the DSN source
 * is stripped before libpq ever sees it. That was the root cause of the
 * regression where multi-entry search_path like "public,private" arrived
 * at the backend as the invalid list "public", — the space-escape was gone.
 *
 * These tests connect for real and ask the backend what it received via
 * SHOW, which is the only way to prove the full chain works.
 */
#[RequiresOperatingSystem('Linux|Darwin')]
#[RequiresPhpExtension('pdo_pgsql')]
class PostgresStartupOptionsTest extends PostgresTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $base = $app['config']->get('database.connections.pgsql');

        $app['config']->set('database.connections.pgsql_startup_search_path', array_merge($base, [
            'search_path' => 'public,private',
        ]));

        $app['config']->set('database.connections.pgsql_startup_isolation', array_merge($base, [
            'isolation_level' => 'read committed',
        ]));

        $app['config']->set('database.connections.pgsql_startup_combined', array_merge($base, [
            'search_path' => 'public,private',
            'timezone' => 'UTC',
            'isolation_level' => 'read committed',
            'synchronous_commit' => 'off',
        ]));
    }

    public function testMultiEntrySearchPathSurvivesDsnTransit(): void
    {
        // The regression case: space after comma in the quoted identifier
        // list must be preserved across PDO → libpq → Postgres.
        $value = DB::connection('pgsql_startup_search_path')
            ->selectOne('SHOW search_path')
            ->search_path;

        $this->assertSame('"public", "private"', $value);
    }

    public function testIsolationLevelWithEmbeddedSpaceSurvivesDsnTransit(): void
    {
        $value = DB::connection('pgsql_startup_isolation')
            ->selectOne('SHOW default_transaction_isolation')
            ->default_transaction_isolation;

        $this->assertSame('read committed', $value);
    }

    public function testCombinedStartupOptionsAllSurviveDsnTransit(): void
    {
        $connection = DB::connection('pgsql_startup_combined');

        $this->assertSame(
            '"public", "private"',
            $connection->selectOne('SHOW search_path')->search_path,
        );
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
