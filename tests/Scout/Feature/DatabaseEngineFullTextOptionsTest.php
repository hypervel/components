<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Feature;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Builder;
use Hypervel\Scout\Engines\DatabaseEngine;
use Hypervel\Scout\SearchableSchema;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Scout\ScoutTestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class DatabaseEngineFullTextOptionsTest extends ScoutTestCase
{
    public function testOptionsOnOtherDriversArePassedThroughUnvalidated(): void
    {
        $this->assertSame(
            ['mode' => 'natural', 'rank' => 'foo', 'expand' => true],
            $this->fullTextOptions(new LegacyFullTextOptionsModel)
        );
    }

    #[RequiresDatabase(['sqlite', 'mysql', 'mariadb'])]
    public function testSearchableSchemaRequiresPostgres(): void
    {
        SearchableSchema::register();

        $this->expectException(RuntimeException::class);

        Schema::create('posts', fn (Blueprint $table) => $table->searchable(['title']));
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsThrow(array $options, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        (fn () => $this->ensureValidFullTextOptions($options))->call(new DatabaseEngine);
    }

    public static function invalidOptions(): array
    {
        return [
            'rank' => [['rank' => 'pg_sleep(1) + ts_rank'], '[rank] must be one of'],
            'trigram mode' => [['trigram_mode' => 'off'], '[trigram_mode] must be one of'],
        ];
    }

    /**
     * Get the full-text options the database engine resolves for the given model.
     */
    protected function fullTextOptions(Model $model): array
    {
        return (fn () => $this->getFullTextOptions(new Builder($model, 'query')))->call(new DatabaseEngine);
    }
}

class LegacyFullTextOptionsModel extends Model
{
    #[SearchUsingFullText(['title'], ['mode' => 'natural', 'rank' => 'foo', 'expand' => true])]
    public function toSearchableArray(): array
    {
        return [];
    }
}
