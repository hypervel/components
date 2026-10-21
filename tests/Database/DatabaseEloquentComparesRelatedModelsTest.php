<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Generator;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\TestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

class DatabaseEloquentComparesRelatedModelsTest extends TestCase
{
    #[DataProvider('relationTypes')]
    public function testIsNotNull(string $type): void
    {
        [$relation] = $this->newRelation($type, 1);

        $this->assertFalse($relation->is(null));
        $this->assertTrue($relation->isNot(null));
    }

    /**
     * Provide each relation type that compares related models.
     */
    public static function relationTypes(): Generator
    {
        foreach (['HasOne', 'MorphOne', 'BelongsTo', 'MorphTo'] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('comparisons')]
    public function testIsComparesKeysTableAndConnection(string $type, mixed $parentKey, mixed $relatedKey, string $table, string $connection, bool $expected): void
    {
        [$relation, $relatedKeyAttribute] = $this->newRelation($type, $parentKey);

        $model = (new ComparesRelatedModelsStub)
            ->setTable($table)
            ->setConnection($connection)
            ->forceFill([$relatedKeyAttribute => $relatedKey]);

        $this->assertSame($expected, $relation->is($model));
        $this->assertSame(! $expected, $relation->isNot($model));
    }

    /**
     * Provide key, table and connection comparisons for each relation type.
     */
    public static function comparisons(): Generator
    {
        $scenarios = [
            'same keys' => ['abc', 'abc', 'table', 'connection', true],
            'integer parent key' => [1, '1', 'table', 'connection', true],
            'integer related key' => ['1', 1, 'table', 'connection', true],
            'integer keys' => [1, 1, 'table', 'connection', true],
            'null parent key' => [null, 1, 'table', 'connection', false],
            'null related key' => [1, null, 'table', 'connection', false],
            'both keys null' => [null, null, 'table', 'connection', false],
            // Only null keys are missing, so empty strings and zeros compare as keys.
            'both keys empty strings' => ['', '', 'table', 'connection', true],
            'integer zero parent key' => [0, '0', 'table', 'connection', true],
            'integer zero related key' => ['0', 0, 'table', 'connection', true],
            'different keys' => [1, 2, 'table', 'connection', false],
            'different table' => [1, 1, 'other_table', 'connection', false],
            'different connection' => [1, 1, 'table', 'other_connection', false],
        ];

        foreach (['HasOne', 'MorphOne', 'BelongsTo', 'MorphTo'] as $type) {
            foreach ($scenarios as $name => $scenario) {
                yield "{$type}: {$name}" => [$type, ...$scenario];
            }
        }
    }

    /**
     * Create a relation of the given type and the related key attribute it compares.
     *
     * @return array{0: Relation, 1: string}
     */
    protected function newRelation(string $type, mixed $parentKey): array
    {
        $related = (new ComparesRelatedModelsStub)->setTable('table')->setConnection('connection');
        $builder = (new Builder((new SQLiteConnection(new PDO('sqlite::memory:')))->query()))->setModel($related);
        $parent = new ComparesRelatedModelsStub;

        return match ($type) {
            'HasOne' => [new HasOne($builder, $parent->forceFill(['id' => $parentKey]), 'foreign_key', 'id'), 'foreign_key'],
            'MorphOne' => [new MorphOne($builder, $parent->forceFill(['id' => $parentKey]), 'morph_type', 'morph_id', 'id'), 'morph_id'],
            'BelongsTo' => [new BelongsTo($builder, $parent->forceFill(['foreign_key' => $parentKey]), 'foreign_key', 'id', 'relation'), 'id'],
            'MorphTo' => [new MorphTo($builder, $parent->forceFill(['foreign_key' => $parentKey]), 'foreign_key', 'id', 'morph_type', 'relation'), 'id'],
        };
    }
}

class ComparesRelatedModelsStub extends Model
{
    protected array $guarded = [];

    protected string $primaryKey = 'uuid';
}
