<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Watchers;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\DB;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;
use Hypervel\Telescope\Watchers\QueryWatcher;
use Hypervel\Tests\Telescope\FeatureTestCase;

/**
 * @internal
 * @coversNothing
 */
class QueryWatcherTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->get(ConfigInterface::class)
            ->set('telescope.watchers', [
                QueryWatcher::class => [
                    'enabled' => true,
                    'slow' => 0.2,
                ],
            ]);

        $this->startTelescope();
    }

    public function testQueryWatcherRegistersDatabaseQueries()
    {
        EntryModel::count();

        $entry = $this->loadTelescopeEntries()->first();

        $this->assertSame(EntryType::QUERY, $entry->type);
        $this->assertSame('select count(*) as aggregate from "telescope_entries"', $entry->content['sql']);
        $this->assertSame('sqlite', $entry->content['connection']);
    }

    public function testQueryWatcherCanPrepareBindings()
    {
        EntryModel::where('type', 'query')
            ->where('should_display_on_index', true)
            ->whereNull('family_hash')
            ->where('sequence', '>', 100)
            ->where('created_at', '<', Carbon::parse('2019-01-01'))
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

        $this->assertSame('sqlite', $entry->content['connection']);
    }

    public function testQueryWatcherCanPrepareNamedBindings()
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
                'created_at' => Carbon::parse('2019-01-01'),
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

        $this->assertSame('sqlite', $entry->content['connection']);
    }

    public function testQueryWatcherCanPrepareBindingsForNonstandardConnections()
    {
        $event = new QueryExecuted(
            <<<'SQL'
select
Method: post
URL: https://fms.example.com/fmi/data/vLatest/databases/Database_Name/layouts/dapi_layout/_find
Data: {
    "query": [
        {
            "kp_iti": "=ITI0130"
        }
    ],
    "limit": 1
}
SQL,
            ['kp_id' => '=ABC001'],
            500,
            new Connection('filemaker'),
        );

        $sql = $this->app->get(QueryWatcher::class)->replaceBindings($event);

        $this->assertSame(<<<'SQL'
select
Method: post
URL: https://fms.example.com/fmi/data/vLatest/databases/Database_Name/layouts/dapi_layout/_find
Data: {
    "query": [
        {
            "kp_iti": "=ITI0130"
        }
    ],
    "limit": 1
}
SQL, $sql);
    }
}
