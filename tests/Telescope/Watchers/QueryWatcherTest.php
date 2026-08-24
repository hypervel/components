<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Watchers\QueryWatcher;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Telescope\FeatureTestCase;
use Mockery as m;
use TypeError;

#[WithConfig('telescope.watchers', [
    QueryWatcher::class => [
        'enabled' => true,
        'slow' => 0.2,
    ],
])]
class QueryWatcherTest extends FeatureTestCase
{
    public function testQueryWatcherRegistersDatabaseQueries(): void
    {
        EntryModel::count();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::QUERY, $entry->type);
        $this->assertSame('select count(*) as "aggregate" from "telescope_entries"', $entry->content['sql']);
        $this->assertSame('testing', $entry->content['connection']);
        $this->assertSame('sqlite', $entry->content['driver']);
    }

    public function testQueryWatcherCanTagSlowQueries(): void
    {
        $bindings = array_map(
            static fn (int $record): string => sprintf('tag-%012d', $record),
            range(1, 300),
        );

        $event = new QueryExecuted(
            'insert into "telescope_monitoring" ("tag") values ' . implode(', ', array_fill(0, count($bindings), '(?)')),
            $bindings,
            500,
            DB::connection(),
        );

        $this->app->make(QueryWatcher::class)->recordQuery($event);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::QUERY, $entry->type);
        $this->assertGreaterThan(300 * 16, strlen($entry->content['sql']));
        $this->assertSame('testing', $entry->content['connection']);
        $this->assertTrue($entry->content['slow']);
    }

    public function testQueryWatcherCanPrepareBindings(): void
    {
        EntryModel::where('type', 'query')
            ->where('should_display_on_index', true)
            ->whereNull('family_hash')
            ->where('sequence', '>', 100)
            ->where('created_at', '<', CarbonImmutable::parse('2019-01-01'))
            ->update([
                'content' => null,
                'should_display_on_index' => false,
            ]);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::QUERY, $entry->type);
        $this->assertSame(
            <<<'SQL'
update "telescope_entries" set "content" = null, "should_display_on_index" = 0 where "type" = 'query' and "should_display_on_index" = 1 and "family_hash" is null and "sequence" > 100 and "created_at" < '2019-01-01 00:00:00'
SQL,
            $entry->content['sql']
        );

        $this->assertSame('testing', $entry->content['connection']);
    }

    public function testQueryWatcherCanPrepareNamedBindings(): void
    {
        // using the "sequence"-condition twice is intentional
        // to test whether named parameters can be used multiple times.

        DB::statement(
            <<<'SQL'
update "telescope_entries" set "content" = :content, "should_display_on_index" = :index_new where "type" = :type and "should_display_on_index" = :index_old and "family_hash" is null and "sequence" > :sequence and "sequence" > :sequence and "created_at" < :created_at
SQL,
            [
                'sequence' => 100,
                'index_old' => 1,
                'type' => 'query',
                'created_at' => CarbonImmutable::parse('2019-01-01'),
                'index_new' => 0,
                'content' => null,
            ]
        );

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::QUERY, $entry->type);
        $this->assertSame(
            <<<'SQL'
update "telescope_entries" set "content" = null, "should_display_on_index" = 0 where "type" = 'query' and "should_display_on_index" = 1 and "family_hash" is null and "sequence" > 100 and "sequence" > 100 and "created_at" < '2019-01-01 00:00:00'
SQL,
            $entry->content['sql']
        );

        $this->assertSame('testing', $entry->content['connection']);
    }

    public function testQueryWatcherUsesTheConnectionsEscapingContract(): void
    {
        [$event, $connection] = $this->queryEvent(
            'select * from records where external_id = ?',
            ['=ABC001'],
        );
        $connection->shouldReceive('escape')
            ->once()
            ->with('=ABC001')
            ->andReturn('FILEMAKER_STRING[=ABC001]');

        $sql = $this->app->make(QueryWatcher::class)->replaceBindings($event);

        $this->assertSame(
            'select * from records where external_id = FILEMAKER_STRING[=ABC001]',
            $sql,
        );
    }

    public function testQueryWatcherSubstitutesEscapedBindingsLiterally(): void
    {
        $binding = <<<'TEXT'
O'Reilly \ café $1 \1
TEXT;
        $quotedBinding = <<<'TEXT'
'O''Reilly \\ café $1 \1'
TEXT;
        [$event, $connection] = $this->queryEvent('select ?', [$binding]);
        $connection->shouldReceive('escape')
            ->once()
            ->with($binding)
            ->andReturn($quotedBinding);

        $this->assertSame(
            'select ' . $quotedBinding,
            $this->app->make(QueryWatcher::class)->replaceBindings($event),
        );
    }

    public function testQueryWatcherMatchesCompleteLiteralNamedBindings(): void
    {
        [$event] = $this->queryEvent(
            'select :id, :id2, :id, :i.d, :iXd',
            [
                'id' => 1,
                'id2' => 2,
                'i.d' => 3,
            ],
        );

        $this->assertSame(
            'select 1, 2, 1, 3, :iXd',
            $this->app->make(QueryWatcher::class)->replaceBindings($event),
        );
    }

    public function testQueryWatcherDoesNotReplacePostgresTypeCasts(): void
    {
        [$event] = $this->queryEvent(
            'select :payload::jsonb, :jsonb',
            ['payload' => 42, 'jsonb' => 43],
        );

        $this->assertSame(
            'select 42::jsonb, 43',
            $this->app->make(QueryWatcher::class)->replaceBindings($event),
        );
    }

    public function testQueryWatcherDoesNotReplacePostgresJsonKeyOperators(): void
    {
        [$event] = $this->queryEvent(
            'select * from "users" where coalesce(("options")::jsonb ?? \'languages\', false) and "id" = ?',
            [42],
        );

        $this->assertSame(
            'select * from "users" where coalesce(("options")::jsonb ?? \'languages\', false) and "id" = 42',
            $this->app->make(QueryWatcher::class)->replaceBindings($event),
        );
    }

    public function testQueryWatcherRedactsBindingsTheConnectionCannotEscape(): void
    {
        $event = new QueryExecuted(
            'select ? as null_byte, ? as invalid_utf8',
            ["before\0after", "\xC3\x28"],
            500,
            DB::connection(),
        );

        $this->app->make(QueryWatcher::class)->recordQuery($event);

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(
            'select [REDACTED: UNESCAPABLE BINDING] as null_byte, [REDACTED: UNESCAPABLE BINDING] as invalid_utf8',
            $entry->content['sql'],
        );
    }

    public function testQueryWatcherDoesNotHideBrokenDriverErrors(): void
    {
        [$event, $connection] = $this->queryEvent('select ?', ['value']);
        $failure = new TypeError('Broken driver.');
        $connection->shouldReceive('escape')->once()->andThrow($failure);

        try {
            $this->app->make(QueryWatcher::class)->replaceBindings($event);
            $this->fail('A broken driver error should remain visible.');
        } catch (TypeError $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testQueryWatcherUsesConfiguredPackageAndPathStackFilters(): void
    {
        $watcher = new class(['ignore_packages' => true, 'ignore_paths' => ['/custom/path']]) extends QueryWatcher {
            public function stackTraceIgnoredPaths(): array
            {
                return $this->ignoredPaths();
            }
        };

        $this->assertSame([
            base_path('vendor' . DIRECTORY_SEPARATOR),
            '/custom/path',
        ], $watcher->stackTraceIgnoredPaths());

        $watcher->setOptions([
            'ignore_packages' => false,
            'ignore_paths' => ['/custom/path'],
        ]);

        $this->assertSame([
            base_path('vendor' . DIRECTORY_SEPARATOR . 'hypervel'),
            '/custom/path',
        ], $watcher->stackTraceIgnoredPaths());
    }

    /**
     * Create a query event and its test connection.
     *
     * @return array{QueryExecuted, Connection}
     */
    private function queryEvent(string $sql, array $bindings): array
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('filemaker');
        $connection->shouldReceive('prepareBindings')->once()->with($bindings)->andReturn($bindings);

        return [new QueryExecuted($sql, $bindings, 500, $connection), $connection];
    }
}
