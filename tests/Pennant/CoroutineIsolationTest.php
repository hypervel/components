<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\PennantState;
use Hypervel\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->make('config');

        $config->set('pennant.default', 'array');
        $config->set('database.connections.other', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
    }

    public function testValuesAreIsolatedBetweenConcurrentCoroutines(): void
    {
        Feature::define('resolved', fn () => CoroutineContext::get('__pennant_test.value'));
        Feature::define('activated', fn () => 'default');
        Feature::activate('activated', 'parent');

        $results = parallel([
            function () {
                CoroutineContext::set('__pennant_test.value', 'first');
                $inherited = Feature::value('activated');
                Feature::value('resolved');
                Feature::activate('activated', 'first');
                usleep(5000);

                return [$inherited, Feature::value('resolved'), Feature::value('activated')];
            },
            function () {
                CoroutineContext::set('__pennant_test.value', 'second');
                $inherited = Feature::value('activated');
                Feature::value('resolved');
                Feature::activate('activated', 'second');
                usleep(5000);

                return [$inherited, Feature::value('resolved'), Feature::value('activated')];
            },
        ]);

        $this->assertSame(['default', 'first', 'first'], $results[0]);
        $this->assertSame(['default', 'second', 'second'], $results[1]);
        $this->assertSame('parent', Feature::value('activated'));
    }

    public function testCopiedContextGetsIndependentValues(): void
    {
        Feature::define('foo', fn () => 'default');
        Feature::activate('foo', 'parent');

        [$child] = parallel([
            function () {
                $inherited = Feature::value('foo');
                Feature::activate('foo', 'child');

                return [$inherited, Feature::value('foo')];
            },
        ], copyContext: true);

        $this->assertSame(['parent', 'child'], $child);
        $this->assertSame('parent', Feature::value('foo'));
    }

    #[DataProvider('transactionFeatures')]
    public function testForkedCoroutinesDontCopyValuesFromAnOpenTransaction(string $feature): void
    {
        $this->defineTransactionFeatures();

        $copied = DB::transaction(function () use ($feature) {
            $this->resolveTransactionFeatures($feature);

            return parallel([fn () => PennantState::current()->values], copyContext: true)[0];
        });

        $this->assertSame($this->valuesOutsideTransaction(), $copied);
    }

    public static function transactionFeatures(): array
    {
        return [
            'driver value' => ['database-feature'],
            'before hook value' => [FeatureWithBeforeHookInTransaction::class],
        ];
    }

    public function testCopyingFromAParentDoesntCopyValuesFromItsOpenTransaction(): void
    {
        $this->defineTransactionFeatures();

        $copied = DB::transaction(function () {
            $this->resolveTransactionFeatures('database-feature');
            $parent = Coroutine::id();

            return parallel([function () use ($parent) {
                CoroutineContext::copyFrom($parent, [PennantState::CONTEXT_KEY]);

                return PennantState::current()->values;
            }])[0];
        });

        $this->assertSame($this->valuesOutsideTransaction(), $copied);
    }

    public function testSwitchingPartitionsUsesSeparateValues(): void
    {
        $partition = 1;
        Feature::resolvePartitionUsing('team_id', function () use (&$partition) {
            return $partition;
        });
        Feature::define('resolved', function () use (&$partition) {
            return "team-{$partition}";
        });
        Feature::define('activated', fn () => 'default');

        $this->assertSame('team-1', Feature::value('resolved'));
        Feature::activate('activated', 'team-1');

        $partition = 2;

        $this->assertSame('team-2', Feature::value('resolved'));
        $this->assertSame('default', Feature::value('activated'));

        $partition = 1;

        $this->assertSame('team-1', Feature::value('resolved'));
        $this->assertSame('team-1', Feature::value('activated'));
    }

    public function testNullConnectionStoreKeepsSeparateValuesPerConnection(): void
    {
        DB::usingConnection('other', function () {
            (require __DIR__ . '/../../src/pennant/database/migrations/2022_11_01_000001_create_features_table.php')->up();
        });
        Feature::store('database')->define('foo', fn () => 'default');

        DB::usingConnection('other', fn () => Feature::store('database')->activate('foo', 'other'));

        $this->assertSame('default', Feature::store('database')->value('foo'));
        $this->assertSame('other', DB::usingConnection('other', fn () => Feature::store('database')->value('foo')));
    }

    /**
     * Define features on the array and database stores.
     */
    protected function defineTransactionFeatures(): void
    {
        Feature::define('array-feature', fn () => 'array-value');
        Feature::store('database')->define('database-feature', fn () => 'database-value');
        Feature::store('database')->define(FeatureWithBeforeHookInTransaction::class);
    }

    /**
     * Resolve the array feature and the given database store feature.
     */
    protected function resolveTransactionFeatures(string $feature): void
    {
        Feature::value('array-feature');
        Feature::store('database')->value($feature);

        $this->assertArrayHasKey(DB::getDefaultConnection(), PennantState::current()->values);
    }

    /**
     * Get the memoized values that don't belong to the open transaction.
     */
    protected function valuesOutsideTransaction(): array
    {
        return ['' => ['array' => ['' => ['array-feature' => [Feature::serializeScope(null) => 'array-value']]]]];
    }
}

class FeatureWithBeforeHookInTransaction
{
    public function resolve(): string
    {
        return 'resolved';
    }

    public function before(): string
    {
        return 'before';
    }
}
