<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Exceptions\FeaturePartitionNotResolved;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\FeaturePartition;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class PartitionTest extends TestCase
{
    use RefreshDatabase;

    protected int|string|null $team = 1;

    /**
     * @var list<string>
     */
    protected array $resolvedStores = [];

    /**
     * @var list<array{string, ?FeaturePartition}>
     */
    protected array $guardedWrites = [];

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');

        $config->set('pennant.default', 'database');
        $config->set('pennant.stores.database.table', 'team_features');
        $config->set('pennant.stores.cached', [
            'driver' => 'database',
            'table' => 'team_features',
            'cache' => ['store' => 'array'],
        ]);
        $config->set('pennant.stores.central', [
            'driver' => 'database',
            'table' => 'features',
            'partitioned' => false,
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        Schema::create('team_features', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id');
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['team_id', 'name', 'scope']);
        });

        Feature::resolvePartitionUsing('team_id', function (string $store) {
            $this->resolvedStores[] = $store;

            return $this->team;
        });

        Feature::guardWritesUsing(function (string $store, ?FeaturePartition $partition): void {
            $this->guardedWrites[] = [$store, $partition];
        });

        Feature::define('foo', fn () => "team-{$this->team}");
        Feature::define('bar', fn () => "team-{$this->team}");
    }

    public function testReadsAndFirstResolutionStayInThePartition(): void
    {
        $this->assertSame('team-1', Feature::for('taylor')->value('foo'));

        $this->team = 2;

        $this->assertSame('team-2', Feature::for('taylor')->value('foo'));

        Feature::flushCache();
        $this->team = 1;

        $this->assertSame('team-1', Feature::for('taylor')->value('foo'));
        $this->assertSame([
            [1, 'foo', 'taylor', '"team-1"'],
            [2, 'foo', 'taylor', '"team-2"'],
        ], $this->rows());
        $this->assertSame(['database'], array_values(array_unique($this->resolvedStores)));
        $this->assertEquals([
            ['database', new FeaturePartition('team_id', 1)],
            ['database', new FeaturePartition('team_id', 2)],
        ], $this->guardedWrites);
    }

    public function testGroupedReadsStayInThePartition(): void
    {
        Feature::getAll(['foo' => ['taylor'], 'bar' => ['otwell']]);

        $this->team = 2;

        $this->assertSame(
            ['foo' => ['team-2'], 'bar' => ['team-2']],
            Feature::getAll(['foo' => ['taylor'], 'bar' => ['otwell']]),
        );
    }

    #[DataProvider('partitionedWrites')]
    public function testWritesStayInThePartition(Closure $write, array $expected): void
    {
        Feature::for(['taylor', 'otwell'])->load(['foo', 'bar']);
        $this->team = 2;
        Feature::for(['taylor', 'otwell'])->load(['foo', 'bar']);

        $write();

        $this->assertSame([
            [1, 'bar', 'otwell', '"team-1"'],
            [1, 'bar', 'taylor', '"team-1"'],
            [1, 'foo', 'otwell', '"team-1"'],
            [1, 'foo', 'taylor', '"team-1"'],
            ...$expected,
        ], $this->rows());
    }

    public static function partitionedWrites(): array
    {
        $bar = [
            [2, 'bar', 'otwell', '"team-2"'],
            [2, 'bar', 'taylor', '"team-2"'],
        ];

        return [
            'set' => [fn () => Feature::store('database')->set('foo', 'taylor', 'changed'), [
                ...$bar,
                [2, 'foo', 'otwell', '"team-2"'],
                [2, 'foo', 'taylor', '"changed"'],
            ]],
            'set many' => [fn () => Feature::for(['taylor', 'otwell'])->activate('foo', 'changed'), [
                ...$bar,
                [2, 'foo', 'otwell', '"changed"'],
                [2, 'foo', 'taylor', '"changed"'],
            ]],
            'set for all scopes' => [fn () => Feature::activateForEveryone('foo', 'changed'), [
                ...$bar,
                [2, 'foo', 'otwell', '"changed"'],
                [2, 'foo', 'taylor', '"changed"'],
            ]],
            'delete' => [fn () => Feature::for('taylor')->forget('foo'), [
                ...$bar,
                [2, 'foo', 'otwell', '"team-2"'],
            ]],
            'purge features' => [fn () => Feature::purge('foo'), $bar],
            'purge everything' => [fn () => Feature::purge(), []],
            'purge command' => [fn () => Artisan::call('pennant:purge', ['features' => ['foo']]), $bar],
        ];
    }

    public function testStoredFeaturesStayInThePartition(): void
    {
        Feature::for('taylor')->value('foo');

        $this->team = 2;
        Feature::for('taylor')->value('bar');

        $this->assertSame(['bar'], Feature::stored());
    }

    public function testUnpartitionedStoresDontResolveThePartition(): void
    {
        $this->team = null;

        Feature::store('central')->for('taylor')->activate('foo', 'central');
        Feature::store('central')->flushCache();

        $this->assertSame('central', Feature::store('central')->for('taylor')->value('foo'));
        $this->assertSame([], $this->resolvedStores);
    }

    #[DataProvider('unresolvedPartitions')]
    public function testUnresolvedPartitionThrows(?string $team): void
    {
        $this->team = $team;

        $this->expectExceptionObject(new FeaturePartitionNotResolved('The [team_id] partition of Pennant store [database] could not be resolved.'));

        Feature::for('taylor')->value('foo');
    }

    public static function unresolvedPartitions(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    public function testIntegerAndStringPartitionValuesShareMemoAndCacheIdentity(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            ++$queries;
        });
        Feature::store('cached')->define('foo', fn () => "team-{$this->team}");

        Feature::store('cached')->for('taylor')->value('foo');
        $this->team = '1';
        $queries = 0;

        $this->assertSame('team-1', Feature::store('cached')->for('taylor')->value('foo'));
        $this->assertSame(0, $queries);

        Feature::store('cached')->flushCache();

        $this->assertSame('team-1', Feature::store('cached')->for('taylor')->value('foo'));
        $this->assertSame(0, $queries);
    }

    public function testPartitionsDontShareCachedValuesOrInvalidation(): void
    {
        Feature::store('cached')->define('foo', fn () => "team-{$this->team}");
        Feature::store('cached')->for('taylor')->value('foo');
        $this->team = 2;
        Feature::store('cached')->for('taylor')->value('foo');
        Feature::store('cached')->flushCache();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            ++$queries;
        });

        $this->assertSame('team-2', Feature::store('cached')->for('taylor')->value('foo'));
        $this->team = 1;
        $this->assertSame('team-1', Feature::store('cached')->for('taylor')->value('foo'));
        $this->assertSame(0, $queries);

        Feature::store('database')->for('taylor')->activate('foo', 'changed');
        Feature::store('cached')->flushCache();
        $this->team = 2;
        $queries = 0;

        $this->assertSame('team-2', Feature::store('cached')->for('taylor')->value('foo'));
        $this->assertSame(0, $queries);

        $this->team = 1;

        $this->assertSame('changed', Feature::store('cached')->for('taylor')->value('foo'));
    }

    public function testPartitionResolverCantBeRegisteredAfterAStoreIsResolved(): void
    {
        $this->expectExceptionObject(new RuntimeException('Register the Pennant partition resolver before resolving any Pennant store.'));

        Feature::resolvePartitionUsing('team_id', fn () => 2);
    }

    #[DataProvider('invalidPartitionColumns')]
    public function testPartitionColumnMustBeASimpleIdentifier(string $column): void
    {
        $this->expectExceptionObject(new InvalidArgumentException("Pennant partition column [{$column}] must be a simple SQL identifier."));

        Feature::resolvePartitionUsing($column, fn () => 1);
    }

    public static function invalidPartitionColumns(): array
    {
        return [
            'qualified' => ['teams.id'],
            'expression' => ['team_id = 1 or 1'],
        ];
    }

    /**
     * Get the stored rows of every partition.
     *
     * @return list<array{int, string, string, string}>
     */
    protected function rows(): array
    {
        return DB::table('team_features')
            ->orderBy('team_id')
            ->orderBy('name')
            ->orderBy('scope')
            ->get()
            ->map(fn (object $row) => [(int) $row->team_id, $row->name, $row->scope, $row->value])
            ->all();
    }
}
