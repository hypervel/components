<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Scout\Database\Postgres;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Searchable;
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
        SearchableModel::create(['id' => PHP_INT_MAX, 'title' => 'Maximum', 'body' => 'Body']);
        SearchableModel::create(['id' => 42, 'title' => 'Forty two', 'body' => 'Body']);

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
