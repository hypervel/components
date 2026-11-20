<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Searchable;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Scout\ScoutTestCase;
use InvalidArgumentException;
use RuntimeException;
use UnitEnum;

#[RequiresDatabase('pgsql')]
class DatabasePgsqlSearchTest extends ScoutTestCase
{
    /**
     * Configure database search and the prefixed and tenant connections.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $connection = $config->array('database.connections.' . $config->string('database.default'));

        $config->set('scout.driver', 'database');
        $config->set('database.connections.pgsql_prefixed', [...$connection, 'prefix' => 'pre_', 'prefix_indexes' => true]);
        $config->set('database.connections.pgsql_tenant', [...$connection, 'search_path' => 'scout_tenant']);
    }

    /**
     * Commit the extension before the test transaction begins, so another connection's
     * "create extension if not exists" doesn't wait for that transaction.
     */
    protected function beforeRefreshingDatabase(): void
    {
        DB::statement('create extension if not exists pg_trgm');
    }

    /**
     * Create the searchable table inside the test transaction.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('scout_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->searchable(['title' => 'A', 'body' => 'D'], 'english', ['title']);
        });
    }

    /**
     * Drop the table the prefixed connection created outside the test transaction.
     */
    protected function tearDownInCoroutine(): void
    {
        Schema::connection('pgsql_prefixed')->dropIfExists('scout_posts');
    }

    public function testSearchableCreatesGeneratedVectorAndGinIndexes(): void
    {
        $this->assertSame(
            ['scout_posts_search_vector_index' => 'CREATE INDEX scout_posts_search_vector_index ON public.scout_posts USING gin (search_vector)',
                'scout_posts_title_trigram' => 'CREATE INDEX scout_posts_title_trigram ON public.scout_posts USING gin (title gin_trgm_ops)'],
            $this->indexes()
        );

        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));

        $this->assertSame([], $this->indexes());
        $this->assertFalse(Schema::hasColumn('scout_posts', 'search_vector'));
    }

    public function testSearchableCanBeAddedToAnExistingTable(): void
    {
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));
        PgsqlPost::create(['title' => 'Laravel Scout']);

        Schema::table('scout_posts', fn (Blueprint $table) => $table->searchable(['title', 'body']));

        $this->assertSame(['scout_posts_search_vector_index'], array_keys($this->indexes()));
        $this->assertSame(['Laravel Scout'], PgsqlPost::search('scout')->get()->pluck('title')->all());
    }

    public function testSearchableCreatesTheTrigramExtension(): void
    {
        DB::statement('drop extension pg_trgm cascade');
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable());

        try {
            Schema::table('scout_posts', fn (Blueprint $table) => $table->searchable(['title'], 'english', ['title']));

            $this->assertTrue(DB::selectOne("select exists(select 1 from pg_extension where extname = 'pg_trgm') as installed")->installed);
        } finally {
            DB::statement('create extension if not exists pg_trgm');
        }
    }

    public function testWeightedVectorSearchRanksTitleMatchesFirstAndMatchesNullColumns(): void
    {
        PgsqlPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlPost::create(['title' => 'Laravel Scout', 'body' => null]);
        PgsqlPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlPost::search('scout')->get()->pluck('title')->all());
    }

    public function testQueryModes(): void
    {
        PgsqlPost::create(['title' => 'Shah Rukh Khan', 'body' => 'Romance']);
        PgsqlPost::create(['title' => 'Salman Khan', 'body' => 'Action']);

        $this->assertSame(['Shah Rukh Khan'], PgsqlWebsearchPost::search('khan -salman')->get()->pluck('title')->all());
        $this->assertSame(['Shah Rukh Khan'], PgsqlPhrasePost::search('rukh khan')->get()->pluck('title')->all());
        $this->assertSame([], PgsqlPhrasePost::search('khan rukh')->get()->all());
    }

    public function testPerQueryOptionsOverrideTheModelOptions(): void
    {
        PgsqlPost::create(['title' => 'Shah Rukh Khan', 'body' => 'Romance']);
        PgsqlPost::create(['title' => 'Salman Khan', 'body' => 'Action']);

        $this->assertSame(['Shah Rukh Khan'], PgsqlPost::search('khan -salman')->options(['mode' => 'websearch'])->get()->pluck('title')->all());
        $this->assertSame(['Salman Khan'], PgsqlPhrasePost::search('salman')->options(['mode' => 'plain'])->get()->pluck('title')->all());
        $this->assertSame(['Salman Khan'], PgsqlPost::search('salman khn')->options(['mode' => 'websearch'])->get()->pluck('title')->all());
        $this->assertCount(2, PgsqlPost::search('khan')->options(['vector' => [0.1, 0.2], 'hybrid' => ['semanticRatio' => 0.5]])->get());
        $this->assertCount(2, PgsqlExpressionPost::search('khan')->options(['vector' => [0.1, 0.2]])->get());
    }

    public function testInvalidPerQueryOptionsThrow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PgsqlPost::search('khan')->options(['rank' => 'pg_sleep(1) + ts_rank'])->get();
    }

    public function testExpressionSearchUsesACustomTextSearchConfig(): void
    {
        DB::statement('drop text search configuration if exists public.scout_simple');
        DB::statement('create text search configuration public.scout_simple (copy = simple)');

        PgsqlExpressionPost::create(['title' => 'The Godfather', 'body' => 'Crime']);

        $this->assertSame([], PgsqlExpressionPost::search('the')->get()->all());
        $this->assertSame(['The Godfather'], PgsqlExpressionPost::search('the')->options(['language' => 'public.scout_simple'])->get()->pluck('title')->all());
    }

    public function testMissingTrigramExtensionThrows(): void
    {
        DB::statement('create schema scout_tenant');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires the pg_trgm extension');

        PgsqlSearchPathPost::search('godfather')->get();
    }

    public function testBlendModeAndTsRankCd(): void
    {
        PgsqlPost::create(['title' => 'The Godfather']);
        PgsqlPost::create(['title' => 'Godfather cameo']);
        PgsqlPost::create(['title' => 'Godzilla']);

        // Equal full-text ranks fall back to the newest row, unless trigram similarity breaks the tie...
        $this->assertSame(['Godfather cameo', 'The Godfather'], PgsqlPost::search('godfather')->get()->pluck('title')->all());
        $this->assertSame(['The Godfather', 'Godfather cameo'], PgsqlPost::search('godfather')->options(['trigram_mode' => 'blend'])->get()->pluck('title')->all());
        $this->assertCount(2, PgsqlPost::search('godfather')->options(['rank' => 'ts_rank_cd'])->get());
        $this->assertSame(['The Godfather', 'Godfather cameo'], PgsqlPost::search('godfathr')->options(['trigram_mode' => 'blend'])->get()->pluck('title')->all());
    }

    public function testSimplePaginationKeepsFallingBackOnLaterPages(): void
    {
        PgsqlPost::create(['title' => 'The Godfather']);
        PgsqlPost::create(['title' => 'The Godfather Part II']);

        $this->assertSame(['The Godfather Part II'], PgsqlPost::search('godfathr')->simplePaginate(1, 'page', 2)->pluck('title')->all());
    }

    public function testSimplePaginationDoesNotFallBackPastTheLastFullTextMatch(): void
    {
        PgsqlPost::create(['title' => 'Godfather']);
        PgsqlPost::create(['title' => 'Godfarther']);

        $first = PgsqlPost::search('godfather')->simplePaginate(1, 'page', 1);

        $this->assertSame(['Godfather'], $first->pluck('title')->all());
        $this->assertFalse($first->hasMorePages());
        $this->assertSame([], PgsqlPost::search('godfather')->simplePaginate(1, 'page', 2)->items());

        // Pagination replaces an offset set on the query, so it must not hide the first match either...
        $offset = fn (EloquentBuilder $query) => $query->offset(1);

        $this->assertSame(['Godfather'], PgsqlPost::search('godfather')->query($offset)->simplePaginate(1, 'page', 1)->pluck('title')->all());
        $this->assertSame([], PgsqlPost::search('godfather')->query($offset)->simplePaginate(1, 'page', 2)->items());
    }

    public function testEquallyRankedResultsPaginateWithoutDuplicates(): void
    {
        foreach (range(1, 5) as $i) {
            PgsqlPost::create(['title' => 'Same title']);
        }

        $ids = collect(range(1, 5))->flatMap(
            fn (int $page) => PgsqlPost::search('same')->paginate(1, 'page', $page)->pluck('id')
        );

        $this->assertSame([5, 4, 3, 2, 1], $ids->all());
    }

    public function testTrigramFallbackRunsOnlyWhenFullTextFindsNothing(): void
    {
        PgsqlPost::create(['title' => 'The Godfather']);
        PgsqlPost::create(['title' => 'Godzilla']);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfather')->get()->pluck('title')->all());
        $this->assertCount(0, array_filter($queries, fn (string $sql): bool => str_contains($sql, '% ?')));

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfathr')->get()->pluck('title')->all());

        $page = PgsqlPost::search('godfathr')->paginate(10);
        $this->assertSame(1, $page->total());
        $this->assertSame(['The Godfather'], $page->pluck('title')->all());

        $this->assertSame(['The Godfather'], PgsqlPost::search('godfathr')->simplePaginate(10)->pluck('title')->all());
        $this->assertSame([], PgsqlPost::search('nothing like it')->get()->all());
    }

    public function testSearchWithoutAVectorColumnUsesExpressionRanking(): void
    {
        PgsqlExpressionPost::create(['title' => 'Queues', 'body' => 'Scout keeps search indexes in sync']);
        PgsqlExpressionPost::create(['title' => 'Laravel Scout', 'body' => 'Scout docs']);
        PgsqlExpressionPost::create(['title' => 'Horizon', 'body' => 'Queue dashboard']);

        $this->assertSame(['Laravel Scout', 'Queues'], PgsqlExpressionPost::search('scout')->get()->pluck('title')->all());
    }

    public function testExpressionSearchMatchesARowWithANullColumn(): void
    {
        PgsqlExpressionPost::create(['title' => 'Laravel Scout', 'body' => null]);

        $this->assertSame(['Laravel Scout'], PgsqlExpressionPost::search('scout')->get()->pluck('title')->all());
    }

    public function testVectorColumnsMatchWithACustomTextSearchConfig(): void
    {
        DB::statement('drop text search configuration if exists public.scout_simple');
        DB::statement('create text search configuration public.scout_simple (copy = simple)');
        Schema::table('scout_posts', fn (Blueprint $table) => $table->dropSearchable(['title']));
        Schema::table('scout_posts', fn (Blueprint $table) => $table->searchable(['title'], 'public.scout_simple'));

        PgsqlPost::create(['title' => 'Running fast']);

        // The "english" config would stem the query to "run" and miss the unstemmed "running" lexeme...
        $this->assertSame(['Running fast'], PgsqlPost::search('running')->options(['language' => 'public.scout_simple'])->get()->pluck('title')->all());
    }

    public function testSoftDeletedAndExplicitlyOrderedSearches(): void
    {
        PgsqlPost::create(['title' => 'Scout A', 'body' => 'scout scout scout']);
        PgsqlPost::create(['title' => 'Scout B'])->delete();
        PgsqlPost::create(['title' => 'Scout C']);

        $this->assertSame(['Scout A', 'Scout C'], PgsqlPost::search('scout')->get()->pluck('title')->all());
        $this->assertSame(['Scout B'], PgsqlPost::search('scout')->onlyTrashed()->get()->pluck('title')->all());
        $this->assertSame(['Scout C', 'Scout A'], PgsqlPost::search('scout')->orderBy('title', 'desc')->get()->pluck('title')->all());
    }

    public function testSchemaQualifiedTables(): void
    {
        DB::statement('create schema scout_tenant');

        $this->assertSearchableTableWorks(Schema::connection(DB::getDefaultConnection()), 'scout_tenant.scout_posts', new PgsqlTenantPost);
    }

    public function testPrefixedTablesOnANamedConnection(): void
    {
        $default = DB::getDefaultConnection();

        // Any accidental use of the default connection now fails...
        config(['database.default' => 'missing']);

        try {
            $this->assertSearchableTableWorks(Schema::connection('pgsql_prefixed'), 'scout_posts', new PgsqlPrefixedPost);
        } finally {
            // The test transaction is rolled back through the default connection.
            config(['database.default' => $default]);
        }
    }

    /**
     * Assert that a searchable table created through the given schema builder can be searched and dropped.
     */
    protected function assertSearchableTableWorks(SchemaBuilder $schema, string $table, Model $model): void
    {
        $schema->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->searchable(['title' => 'A', 'body' => 'D'], 'english', ['title']);
        });

        $model->newQuery()->create(['title' => 'The Godfather']);
        $model->newQuery()->create(['title' => 'Godzilla', 'body' => 'godfather cameo']);

        $this->assertSame(['The Godfather', 'Godzilla'], $model::search('godfather')->get()->pluck('title')->all());
        $this->assertSame(['The Godfather'], $model::search('godfathr')->get()->pluck('title')->all());
        $this->assertSame(1, $model::search('godfathr')->paginate()->total());

        $schema->table($table, fn (Blueprint $table) => $table->dropSearchable(['title']));

        $this->assertFalse($schema->hasColumn($table, 'search_vector'));
    }

    /**
     * Get the definitions of the searchable table's indexes, keyed by name.
     *
     * @return array<string, string>
     */
    protected function indexes(): array
    {
        return collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'scout_posts' and indexname <> 'scout_posts_pkey' order by indexname"))
            ->pluck('indexdef', 'indexname')
            ->all();
    }
}

class PgsqlPost extends Model
{
    use Searchable;
    use SoftDeletes;

    protected ?string $table = 'scout_posts';

    protected array $guarded = [];

    #[SearchUsingFullText(['search_vector'], ['vector' => true, 'trigram' => ['title']])]
    public function toSearchableArray(): array
    {
        return ['search_vector' => null];
    }
}

class PgsqlExpressionPost extends PgsqlPost
{
    #[SearchUsingFullText(['title', 'body'], ['language' => 'english'])]
    public function toSearchableArray(): array
    {
        return ['title' => $this->title, 'body' => $this->body];
    }
}

class PgsqlPhrasePost extends PgsqlPost
{
    #[SearchUsingFullText(['search_vector'], ['vector' => true, 'mode' => 'phrase'])]
    public function toSearchableArray(): array
    {
        return ['search_vector' => null];
    }
}

class PgsqlWebsearchPost extends PgsqlPost
{
    #[SearchUsingFullText(['search_vector'], ['vector' => true, 'mode' => 'websearch'])]
    public function toSearchableArray(): array
    {
        return ['search_vector' => null];
    }
}

class PgsqlTenantPost extends PgsqlPost
{
    protected ?string $table = 'scout_tenant.scout_posts';
}

class PgsqlSearchPathPost extends PgsqlPost
{
    protected UnitEnum|string|null $connection = 'pgsql_tenant';
}

class PgsqlPrefixedPost extends PgsqlPost
{
    protected UnitEnum|string|null $connection = 'pgsql_prefixed';
}
