<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Pennant\Database;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\PennantServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

abstract class DatabaseDriverTestCase extends DatabaseTestCase
{
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [PennantServiceProvider::class];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');

        $config->set('pennant.default', 'database');
        $config->set('database.connections.competitor', $config->get('database.connections.' . $config->string('database.default')));
    }

    protected function afterRefreshingDatabase(): void
    {
        (require __DIR__ . '/../../../../src/pennant/database/migrations/2022_11_01_000001_create_features_table.php')->up();
    }

    #[DataProvider('similarIdentifiers')]
    public function testSimilarIdentifiersAreStoredSeparately(string $first, string $second): void
    {
        Feature::for($first)->activate('foo', 'first');
        Feature::for($second)->activate('foo', 'second');
        Feature::for('shared-scope')->activate($first, 'first');
        Feature::for('shared-scope')->activate($second, 'second');
        Feature::flushCache();

        $this->assertSame('first', Feature::for($first)->value('foo'));
        $this->assertSame('second', Feature::for($second)->value('foo'));
        $this->assertSame('first', Feature::for('shared-scope')->value($first));
        $this->assertSame('second', Feature::for('shared-scope')->value($second));
        $this->assertSame(4, DB::table('features')->count());
    }

    public static function similarIdentifiers(): array
    {
        return [
            'case' => ['Team-A', 'team-a'],
            'accents' => ['café', 'cafe'],
            'trailing spaces' => ['scope', 'scope '],
            'composed and decomposed' => ["caf\u{e9}", "cafe\u{301}"],
        ];
    }

    public function testLongFourByteIdentifiersAreStored(): void
    {
        $identifier = str_repeat("\u{1F600}", 255);

        Feature::for($identifier)->activate($identifier, 'stored');
        Feature::flushCache();

        $this->assertSame('stored', Feature::for($identifier)->value($identifier));
    }

    #[DefineEnvironment('cachingStoredValuesInTheDatabase')]
    public function testLongIdentifiersCanBeCachedInADatabaseCacheStore(): void
    {
        (require __DIR__ . '/../../../../src/testbench/hypervel/migrations/0001_01_01_000003_testbench_create_cache_table.php')->up();
        $feature = str_repeat('f', 255);
        $scope = str_repeat('s', 255);
        Feature::define($feature, fn () => 'resolved');

        $this->assertSame('resolved', Feature::for($scope)->value($feature));

        Feature::flushCache();
        DB::table('features')->update(['value' => json_encode('changed outside Pennant')]);

        $this->assertSame('resolved', Feature::for($scope)->value($feature));
    }

    /**
     * Share the default store's values through the database cache store.
     */
    protected function cachingStoredValuesInTheDatabase(ApplicationContract $app): void
    {
        $app->make('config')->set('pennant.stores.database.cache', ['store' => 'database']);
    }

    public function testFirstResolutionUsesOneReadAndOneInsert(): void
    {
        Feature::define('foo', fn () => 'resolved');
        DB::enableQueryLog();

        $this->assertSame(['resolved', 'resolved'], Feature::for(['tim', 'taylor'])->load('foo')['foo']);
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testConcurrentWinnerIsReturned(): void
    {
        Feature::define('foo', fn () => 'candidate');
        $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $this->assertSame('winner', Feature::for('tim')->value('foo'));
    }

    /**
     * Store a competing row through another connection after the next read, before Pennant inserts its own.
     */
    protected function storeWinnerBeforeInsert(string $feature, string $scope, mixed $value): void
    {
        $stored = false;

        DB::listen(function (QueryExecuted $event) use ($feature, $scope, $value, &$stored) {
            if (! $stored && str_starts_with($event->sql, 'select')) {
                $stored = true;

                DB::connection('competitor')->table('features')->insert([
                    'name' => $feature,
                    'scope' => $scope,
                    'value' => json_encode($value),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });
    }
}
