<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Searchable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Scout\Fixtures\Models\SearchableModel;
use Hypervel\Tests\Scout\ScoutTestCase;

#[RequiresDatabase('pgsql')]
class DatabaseEnginePostgresIntegrationTest extends ScoutTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('scout.driver', 'database');
    }

    public function testDefaultIntegerKeyIsExcludedFromPostgresTextPredicates(): void
    {
        SearchableModel::create(['title' => 'A searchable phrase', 'body' => 'Body']);

        $results = SearchableModel::search('searchable')->get();

        $this->assertCount(1, $results);
        $this->assertSame('A searchable phrase', $results->first()->title);
    }

    public function testAnnotatedIntegerKeyIsExcludedFromFullTextSearchAndRelevance(): void
    {
        PostgresIntegerKeyFullTextModel::create(['id' => 1, 'title' => 'Shared phrase', 'body' => 'Body']);
        PostgresIntegerKeyFullTextModel::create(['id' => 2, 'title' => 'Shared phrase', 'body' => 'Body']);

        $results = PostgresIntegerKeyFullTextModel::search('Shared')->get();

        $this->assertSame([2, 1], $results->pluck('id')->all());
    }

    public function testIntegerKeyEqualityNormalizesLeadingZeroesAndRejectsOverflow(): void
    {
        SearchableModel::create(['id' => PHP_INT_MIN, 'title' => 'Minimum', 'body' => 'Body']);
        SearchableModel::create(['id' => -42, 'title' => 'Minus forty two', 'body' => 'Body']);
        SearchableModel::create(['id' => 0, 'title' => 'Zero', 'body' => 'Body']);
        SearchableModel::create(['id' => 42, 'title' => 'Forty two', 'body' => 'Body']);
        SearchableModel::create(['id' => PHP_INT_MAX, 'title' => 'Maximum', 'body' => 'Body']);

        $this->assertSame(
            [PHP_INT_MIN],
            SearchableModel::search((string) PHP_INT_MIN)->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [-42],
            SearchableModel::search('-42')->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [-42],
            SearchableModel::search('-00042')->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [0],
            SearchableModel::search('-0')->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [PHP_INT_MAX],
            SearchableModel::search((string) PHP_INT_MAX)->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [42],
            SearchableModel::search('00042')->get()->pluck('id')->all(),
        );
        $this->assertCount(
            0,
            SearchableModel::search('9223372036854775808')->get(),
        );
        $this->assertCount(
            0,
            SearchableModel::search('-9223372036854775809')->get(),
        );
    }

    public function testOutOfRangeIntegerQueryDoesNotFailOnNarrowPostgresIntegerColumns(): void
    {
        Schema::create('postgres_smallint_searchable_models', function (Blueprint $table): void {
            $table->smallInteger('id')->primary();
            $table->string('title');
        });

        PostgresSmallIntSearchableModel::create([
            'id' => 1,
            'title' => 'Order 32768 and 3000000000',
        ]);

        $this->assertSame(
            [1],
            PostgresSmallIntSearchableModel::search('1')->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [1],
            PostgresSmallIntSearchableModel::search('32768')->get()->pluck('id')->all(),
        );
        $this->assertSame(
            [1],
            PostgresSmallIntSearchableModel::search('3000000000')->get()->pluck('id')->all(),
        );
        $this->assertCount(
            0,
            PostgresSmallIntSearchableModel::search('-32769')->get(),
        );
    }

    public function testUnmatchedSearchReturnsNoResultsOnIntegerKeyOnlyModel(): void
    {
        PostgresIntegerKeyOnlyModel::create(['id' => 42, 'title' => 'Alpha', 'body' => 'Body']);
        PostgresIntegerKeyOnlyModel::create(['id' => 99, 'title' => 'Beta', 'body' => 'Body']);

        $this->assertCount(0, PostgresIntegerKeyOnlyModel::search('Alpha')->get());
        $this->assertCount(0, PostgresIntegerKeyOnlyModel::search('9223372036854775808')->get());
        $this->assertSame(
            [42],
            PostgresIntegerKeyOnlyModel::search('42')->get()->pluck('id')->all(),
        );
    }

    public function testCustomIntegerScoutKeyIsExcludedFromPostgresTextPredicates(): void
    {
        PostgresCustomIntegerScoutKeyModel::create([
            'intermediate_id' => 42,
            'title' => 'A searchable phrase',
        ]);

        $textResults = PostgresCustomIntegerScoutKeyModel::search('searchable')->get();

        $this->assertCount(1, $textResults);
        $this->assertSame(42, $textResults->first()->intermediate_id);

        $numericResults = PostgresCustomIntegerScoutKeyModel::search('42')->get();

        $this->assertCount(1, $numericResults);
        $this->assertSame(42, $numericResults->first()->intermediate_id);
    }

    public function testQualifiedIntegerKeyIsExcludedFromPostgresTextAndFullTextPredicates(): void
    {
        PostgresQualifiedIntegerKeyModel::create(['id' => 42, 'title' => 'A qualified phrase', 'body' => 'Body']);

        $textResults = PostgresQualifiedIntegerKeyModel::search('qualified')->get();

        $this->assertCount(1, $textResults);
        $this->assertSame(42, $textResults->first()->id);

        $numericResults = PostgresQualifiedIntegerKeyModel::search('42')->get();

        $this->assertCount(1, $numericResults);
        $this->assertSame(42, $numericResults->first()->id);
    }
}

class PostgresIntegerKeyFullTextModel extends SearchableModel
{
    #[SearchUsingFullText(columns: ['id'])]
    public function toSearchableArray(): array
    {
        return parent::toSearchableArray();
    }
}

class PostgresCustomIntegerScoutKeyModel extends Model
{
    use Searchable;

    protected ?string $table = 'scout_through_models';

    protected array $guarded = [];

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string
    {
        return 'intermediate_id';
    }

    /**
     * Get the key type used to query the model.
     */
    public function getScoutKeyType(): string
    {
        return 'int';
    }

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'intermediate_id' => $this->intermediate_id,
            'title' => $this->title,
        ];
    }
}

class PostgresSmallIntSearchableModel extends Model
{
    use Searchable;

    protected ?string $table = 'postgres_smallint_searchable_models';

    public bool $incrementing = false;

    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
        ];
    }
}

class PostgresIntegerKeyOnlyModel extends SearchableModel
{
    /**
     * Get the indexable data array for the model.
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
        ];
    }
}

class PostgresQualifiedIntegerKeyModel extends SearchableModel
{
    /**
     * Get the indexable data array for the model.
     */
    #[SearchUsingFullText(columns: ['searchable_models.id'])]
    public function toSearchableArray(): array
    {
        return [
            'searchable_models.id' => $this->id,
            'title' => $this->title,
        ];
    }
}
