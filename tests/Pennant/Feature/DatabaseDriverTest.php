<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Closure;
use Exception;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Attributes\Name;
use Hypervel\Pennant\Contracts\FeatureScopeable;
use Hypervel\Pennant\Contracts\FeatureScopeSerializeable;
use Hypervel\Pennant\Drivers\DatabaseDriver;
use Hypervel\Pennant\Events\AllFeaturesPurged;
use Hypervel\Pennant\Events\DynamicallyRegisteringFeatureClass;
use Hypervel\Pennant\Events\FeatureDeleted;
use Hypervel\Pennant\Events\FeatureResolved;
use Hypervel\Pennant\Events\FeaturesPurged;
use Hypervel\Pennant\Events\FeatureUpdated;
use Hypervel\Pennant\Events\FeatureUpdatedForAllScopes;
use Hypervel\Pennant\Events\UnexpectedNullScopeEncountered;
use Hypervel\Pennant\Events\UnknownFeatureResolved;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\GlobalScope;
use Hypervel\Pennant\Migrations\PennantMigration;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Str;
use Hypervel\Testbench\Concerns\WithHypervelMigrations;
use Hypervel\Tests\Pennant\Fixtures\Team;
use Hypervel\Tests\Pennant\Fixtures\User;
use Hypervel\Tests\Pennant\Fixtures\UserFactory;
use Hypervel\Tests\Pennant\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;

class DatabaseDriverTest extends TestCase
{
    use RefreshDatabase;
    use WithHypervelMigrations;

    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'database');
    }

    protected function setUpInCoroutine(): void
    {
        DB::enableQueryLog();
    }

    public function testItDefaultsToFalseForUnknownValues(): void
    {
        $result = Feature::active('foo');

        $this->assertFalse($result);

        $this->assertCount(1, DB::getQueryLog());
    }

    public function testItDispatchesEventsOnUnknownFeatureChecks(): void
    {
        Event::fake([UnknownFeatureResolved::class]);

        Feature::active('foo');

        Event::assertDispatchedTimes(UnknownFeatureResolved::class, 1);
        Event::assertDispatched(function (UnknownFeatureResolved $event) {
            $this->assertSame('foo', $event->feature);
            $this->assertNull($event->scope);

            return true;
        });

        $this->assertCount(1, DB::getQueryLog());

        // The store is resolved now, so this checks the driver doesn't hold on to the earlier dispatcher.
        Event::fake([UnknownFeatureResolved::class]);

        Feature::active('bar');

        Event::assertDispatchedTimes(UnknownFeatureResolved::class, 1);
        Event::assertDispatched(fn (UnknownFeatureResolved $event) => $event->feature === 'bar');
    }

    public function testItCanRegisterDefaultBooleanValues(): void
    {
        Feature::define('true', fn () => true);
        Feature::define('false', fn () => false);

        $true = Feature::active('true');
        $false = Feature::active('false');

        $this->assertTrue($true);
        $this->assertFalse($false);

        $this->assertCount(4, DB::getQueryLog());
    }

    public function testItCanRegisterComplexValues(): void
    {
        Feature::define('config', fn () => [
            'color' => 'red',
            'default' => 'api',
        ]);

        $active = Feature::active('config');
        $value = Feature::value('config');

        $this->assertTrue($active);
        $this->assertSame([
            'color' => 'red',
            'default' => 'api',
        ], $value);

        Feature::for('tim')->activate('new-api', 'foo');

        $active = Feature::for('tim')->active('new-api');
        $value = Feature::for('tim')->value('new-api');

        $this->assertTrue($active);
        $this->assertSame('foo', $value);

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItCachesStateAfterResolving(): void
    {
        $called = 0;
        Feature::define('foo', function () use (&$called) {
            ++$called;

            return true;
        });

        $this->assertSame(0, $called);

        Feature::active('foo');
        $this->assertSame(1, $called);

        Feature::active('foo');
        $this->assertSame(1, $called);

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testNonFalseRegisteredValuesAreConsideredActive(): void
    {
        Feature::define('true', fn () => true);
        Feature::define('false', fn () => false);
        Feature::define('one', fn () => 1);
        Feature::define('zero', fn () => 0);
        Feature::define('null', fn () => null);
        Feature::define('empty-string', fn () => '');

        $this->assertTrue(Feature::active('true'));
        $this->assertFalse(Feature::active('false'));
        $this->assertTrue(Feature::active('one'));
        $this->assertTrue(Feature::active('zero'));
        $this->assertTrue(Feature::active('null'));
        $this->assertTrue(Feature::active('empty-string'));

        $this->assertFalse(Feature::inactive('true'));
        $this->assertTrue(Feature::inactive('false'));
        $this->assertFalse(Feature::inactive('one'));
        $this->assertFalse(Feature::inactive('zero'));
        $this->assertFalse(Feature::inactive('null'));
        $this->assertFalse(Feature::inactive('empty-string'));

        $this->assertCount(12, DB::getQueryLog());
    }

    public function testItCanProgramaticallyActivateAndDeativateFeatures(): void
    {
        Feature::activate('foo');
        $this->assertTrue(Feature::active('foo'));

        Feature::deactivate('foo');
        $this->assertFalse(Feature::active('foo'));

        Feature::activate('foo');
        $this->assertTrue(Feature::active('foo'));

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItDispatchesEventsWhenCheckingKnownFeatures(): void
    {
        Event::fake([FeatureResolved::class]);
        Feature::define('foo', fn () => true);

        Feature::active('foo');
        Feature::active('foo');

        Event::assertDispatchedTimes(FeatureResolved::class, 1);
        Event::assertDispatched(function (FeatureResolved $event) {
            return $event->feature === 'foo' && $event->scope === null;
        });

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItCanActivateAndDeactivateSeveralFeaturesAtOnce(): void
    {
        Feature::activate(['foo', 'bar']);

        $this->assertTrue(Feature::active('foo'));
        $this->assertTrue(Feature::active('bar'));
        $this->assertFalse(Feature::active('baz'));

        Feature::deactivate(['foo', 'bar']);

        $this->assertFalse(Feature::active('foo'));
        $this->assertFalse(Feature::active('bar'));
        $this->assertFalse(Feature::active('baz'));

        Feature::activate(['bar', 'baz']);

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::active('bar'));
        $this->assertTrue(Feature::active('baz'));

        $this->assertCount(4, DB::getQueryLog());
    }

    public function testItCanCheckIfMultipleFeaturesAreActiveAtOnce(): void
    {
        Feature::activate(['foo', 'bar']);

        $this->assertTrue(Feature::allAreActive(['foo']));
        $this->assertTrue(Feature::allAreActive(['foo', 'bar']));
        $this->assertFalse(Feature::allAreActive(['foo', 'bar', 'baz']));

        Feature::deactivate('baz');

        $this->assertTrue(Feature::allAreActive(['foo']));
        $this->assertTrue(Feature::allAreActive(['foo', 'bar']));
        $this->assertFalse(Feature::allAreActive(['foo', 'bar', 'baz']));

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItCanScopeFeatures(): void
    {
        $active = new User(['id' => 1]);
        $inactive = new User(['id' => 2]);
        $captured = [];

        Feature::define('foo', function ($scope) use (&$captured) {
            $captured[] = $scope;

            return $scope?->id === 1;
        });

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for($active)->active('foo'));
        $this->assertFalse(Feature::for($inactive)->active('foo'));
        $this->assertSame([null, $active, $inactive], $captured);

        $this->assertCount(6, DB::getQueryLog());
    }

    public function testItCanActivateAndDeactivateFeaturesWithScope(): void
    {
        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);

        Feature::for($first)->activate('foo');

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for($first)->active('foo'));
        $this->assertFalse(Feature::for($second)->active('foo'));

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItCanActivateAndDeactivateFeaturesForMultipleScopeAtOnce(): void
    {
        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);
        $third = new User(['id' => 3]);

        Feature::for([$first, $second])->activate('foo');

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for($first)->active('foo'));
        $this->assertTrue(Feature::for($second)->active('foo'));
        $this->assertFalse(Feature::for($third)->active('foo'));

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItCanActivateAndDeactivateMultipleFeaturesForMultipleScopeAtOnce(): void
    {
        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);
        $third = new User(['id' => 3]);

        Feature::for([$first, $second])->activate(['foo', 'bar']);

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for($first)->active('foo'));
        $this->assertTrue(Feature::for($second)->active('foo'));
        $this->assertFalse(Feature::for($third)->active('foo'));

        $this->assertFalse(Feature::active('bar'));
        $this->assertTrue(Feature::for($first)->active('bar'));
        $this->assertTrue(Feature::for($second)->active('bar'));
        $this->assertFalse(Feature::for($third)->active('bar'));

        $this->assertCount(5, DB::getQueryLog());
    }

    public function testItCanCheckMultipleFeaturesForMultipleScopeAtOnce(): void
    {
        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);
        $third = new User(['id' => 3]);

        Feature::for([$first, $second])->activate(['foo', 'bar']);

        $this->assertFalse(Feature::allAreActive(['foo', 'bar']));
        $this->assertTrue(Feature::for($first)->allAreActive(['foo', 'bar']));
        $this->assertTrue(Feature::for($second)->allAreActive(['foo', 'bar']));
        $this->assertFalse(Feature::for($third)->allAreActive(['foo', 'bar']));

        $this->assertTrue(Feature::for([$first, $second])->allAreActive(['foo', 'bar']));
        $this->assertFalse(Feature::for([$second, $third])->allAreActive(['foo', 'bar']));
        $this->assertFalse(Feature::for([$first, $second, $third])->allAreActive(['foo', 'bar']));

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testNullIsSameAsNoScope(): void
    {
        Feature::activate('foo');

        $this->assertTrue(Feature::for(null)->active('foo'));

        $this->assertCount(1, DB::getQueryLog());
    }

    public function testItSeesNullAndEmptyStringAsDifferentThings(): void
    {
        Feature::activate('foo');

        $this->assertFalse(Feature::for('')->active('foo'));
        $this->assertTrue(Feature::for(null)->active('foo'));
        $this->assertTrue(Feature::active('foo'));

        Feature::for('')->activate('bar');

        $this->assertTrue(Feature::for('')->active('bar'));
        $this->assertFalse(Feature::for(null)->active('bar'));
        $this->assertFalse(Feature::active('bar'));

        $this->assertCount(4, DB::getQueryLog());
    }

    public function testItMatchesScopesExactly(): void
    {
        Feature::define('foo', fn () => 'resolved');
        Feature::for('1')->activate('foo', 'one');
        Feature::flushCache();

        $this->assertSame(['foo' => ['one', 'resolved']], Feature::getAll(['foo' => ['1', '01']]));
    }

    public function testScopeCanBeStringsLikeEmailAddresses(): void
    {
        Feature::for('tim@hypervel.com')->activate('foo');

        $this->assertFalse(Feature::for('james@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for('tim@hypervel.com')->active('foo'));

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItCanHandleFeatureScopeableObjects(): void
    {
        Feature::define('foo', function ($scope) {
            return $scope === 'tim@hypervel.com';
        });
        $scopeable = fn ($email) => new class(['email' => $email]) extends User implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return $this->email;
            }
        };

        $this->assertTrue(Feature::for($scopeable('tim@hypervel.com'))->active('foo'));
        $this->assertFalse(Feature::for($scopeable('james@hypervel.com'))->active('foo'));

        $this->assertCount(4, DB::getQueryLog());

        $scope = DB::table('features')->get('scope');
        $this->assertSame([
            'james@hypervel.com',
            'tim@hypervel.com',
        ], $scope->pluck('scope')->all());
    }

    public function testItSerializesEloquentModels(): void
    {
        Feature::for(UserFactory::new()->create())->activate('foo');

        $scope = DB::table('features')->value('scope');

        $this->assertStringContainsString('Hypervel\Tests\Pennant\Fixtures\User|1', $scope);
    }

    public function testItRejectsModelsWithoutAKeyAsScope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to serialize a model without a key as the feature scope. Save the model or pass a scope that identifies it.');

        Feature::for(new User)->active('foo');
    }

    public function testItCanManuallySerializeScope(): void
    {
        Feature::define('foo', function ($scope) {
            return $scope->email === 'tim@hypervel.com';
        });
        $scopeable = fn ($email) => new class(['email' => $email]) extends User implements FeatureScopeSerializeable {
            public function featureScopeSerialize(): string
            {
                return $this->email;
            }
        };

        $this->assertTrue(Feature::for($scopeable('tim@hypervel.com'))->active('foo'));
        $this->assertFalse(Feature::for($scopeable('james@hypervel.com'))->active('foo'));

        $this->assertCount(4, DB::getQueryLog());

        $scope = DB::table('features')->get('scope');
        $this->assertSame([
            'james@hypervel.com',
            'tim@hypervel.com',
        ], $scope->pluck('scope')->all());
    }

    public function testItCanLoadFeatureStateIntoMemory(): void
    {
        $called = ['foo' => 0, 'bar' => 0];
        Feature::define('foo', function () use (&$called) {
            ++$called['foo'];
        });
        Feature::define('bar', function () use (&$called) {
            ++$called['bar'];
        });

        $this->assertSame(0, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::load('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::load(['foo']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::load('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::load(['bar']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::load(['foo', 'bar']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::allAreActive(['foo', 'bar']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        $this->assertCount(7, DB::getQueryLog());
    }

    public function testItCanLoadScopedFeatureStateIntoMemory(): void
    {
        $called = ['foo' => 0, 'bar' => 0];
        Feature::define('foo', function ($scope) use (&$called) {
            ++$called['foo'];
        });
        Feature::define('bar', function () use (&$called) {
            ++$called['bar'];
        });

        $this->assertSame(0, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load(['foo']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load(['bar']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::for('loaded')->active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::for('noloaded')->active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(2, $called['bar']);

        Feature::getAll([
            'foo' => [1, 2, 3],
            'bar' => [2],
        ]);
        $this->assertSame(4, $called['foo']);
        $this->assertSame(3, $called['bar']);

        Feature::for([1, 2, 3])->active('foo');
        Feature::for([2])->active('bar');
        $this->assertSame(4, $called['foo']);
        $this->assertSame(3, $called['bar']);

        $this->assertCount(9, DB::getQueryLog());
    }

    public function testItCanLoadAgainstScope(): void
    {
        $called = ['foo' => 0, 'bar' => 0];
        Feature::define('foo', function ($scope) use (&$called) {
            ++$called['foo'];
        });
        Feature::define('bar', function () use (&$called) {
            ++$called['bar'];
        });

        $this->assertSame(0, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load(['foo']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::for('loaded')->load('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::for('loaded')->active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::for('noloaded')->active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(2, $called['bar']);

        Feature::for([1, 2, 3])->load(['foo']);
        Feature::for(2)->load(['bar']);
        $this->assertSame(4, $called['foo']);
        $this->assertSame(3, $called['bar']);

        Feature::for([1, 2, 3])->active('foo');
        Feature::for([2])->active('bar');
        $this->assertSame(4, $called['foo']);
        $this->assertSame(3, $called['bar']);

        $this->assertCount(11, DB::getQueryLog());
    }

    public function testItCanLoadMissingFeatureStateIntoMemory(): void
    {
        $called = ['foo' => 0, 'bar' => 0];
        Feature::define('foo', function () use (&$called) {
            ++$called['foo'];
        });
        Feature::define('bar', function () use (&$called) {
            ++$called['bar'];
        });

        $this->assertSame(0, $called['foo']);

        Feature::loadMissing('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::loadMissing('foo');
        $this->assertSame(0, $called['bar']);

        Feature::active('foo');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(0, $called['bar']);

        Feature::active('bar');
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::loadMissing(['bar']);
        $this->assertSame(1, $called['foo']);
        $this->assertSame(1, $called['bar']);

        Feature::getAllMissing([
            'foo' => [1, 2, 3],
            'bar' => [2],
        ]);
        $this->assertSame(4, $called['foo']);
        $this->assertSame(2, $called['bar']);

        Feature::for([1, 2, 3])->active('foo');
        Feature::for([2])->active('bar');
        $this->assertSame(4, $called['foo']);
        $this->assertSame(2, $called['bar']);

        $this->assertCount(6, DB::getQueryLog());
    }

    public function testItDoesNotHitDbWhenFeaturesAreEmpty(): void
    {
        Feature::load([]);
        Feature::loadMissing([]);
        $this->assertCount(0, DB::getQueryLog());

        Feature::active('foo');
        Feature::loadMissing(['foo']);
        $this->assertCount(1, DB::getQueryLog());
    }

    public function testUnknownFeaturesAreNoPersistedWhenLoading(): void
    {
        Event::fake([UnknownFeatureResolved::class]);
        Feature::load(['foo', 'bar']);

        Event::assertDispatchedTimes(UnknownFeatureResolved::class, 2);
        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount(0, DB::table('features')->get());
    }

    public function testMissingResultsAreInsertedOnLoad(): void
    {
        Feature::define('foo', function () {
            return 1;
        });
        Feature::define('bar', function () {
            return 2;
        });

        Feature::for('taylor@hypervel.com')->activate('foo', 99);
        Feature::for(['tim@hypervel.com', 'jess@hypervel.com', 'taylor@hypervel.com'])->load(['foo', 'bar']);

        $this->assertCount(3, DB::getQueryLog());
        $this->assertDatabaseHas('features', [
            'name' => 'foo',
            'scope' => 'tim@hypervel.com',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('features', [
            'name' => 'foo',
            'scope' => 'jess@hypervel.com',
            'value' => '1',
        ]);
        $this->assertDatabaseHas('features', [
            'name' => 'foo',
            'scope' => 'taylor@hypervel.com',
            'value' => '99',
        ]);
        $this->assertDatabaseHas('features', [
            'name' => 'bar',
            'scope' => 'tim@hypervel.com',
            'value' => '2',
        ]);
        $this->assertDatabaseHas('features', [
            'name' => 'bar',
            'scope' => 'jess@hypervel.com',
            'value' => '2',
        ]);
        $this->assertDatabaseHas('features', [
            'name' => 'bar',
            'scope' => 'taylor@hypervel.com',
            'value' => '2',
        ]);
    }

    #[DataProvider('groupedReads')]
    public function testItReadsFeaturesThatShareScopesTogether(array $features, int $bindings): void
    {
        Feature::getAll($features);

        $this->assertCount(1, DB::getQueryLog());
        $this->assertCount($bindings, DB::getQueryLog()[0]['bindings']);
    }

    public static function groupedReads(): array
    {
        return [
            'rectangular' => [['foo' => ['tim', 'taylor', 'jess'], 'bar' => ['tim', 'taylor', 'jess']], 5],
            'sparse' => [['foo' => ['tim'], 'bar' => ['tim'], 'baz' => ['taylor', 'jess']], 6],
        ];
    }

    public function testItSplitsReadsAtTheGroupLimit(): void
    {
        $features = [];

        for ($index = 0; $index <= DatabaseDriver::QUERY_GROUP_LIMIT; ++$index) {
            $features["feature-{$index}"] = ["scope-{$index}"];
        }

        Feature::getAll($features);

        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItSplitsReadsAtTheBindingLimit(): void
    {
        Feature::getAll(['foo' => array_map(fn (int $index) => "scope-{$index}", range(1, DatabaseDriver::QUERY_BINDING_LIMIT))]);

        $this->assertSame(
            [DatabaseDriver::QUERY_BINDING_LIMIT, 2],
            array_map(fn (array $query) => count($query['bindings']), DB::getQueryLog()),
        );
    }

    public function testItReadsAndInsertsRepeatedScopesOnce(): void
    {
        Feature::define('foo', fn () => 'resolved');

        $this->assertSame(['foo' => ['resolved', 'resolved']], Feature::getAll(['foo' => ['tim', 'tim']]));
        $this->assertSame([2, 5], array_map(fn (array $query) => count($query['bindings']), DB::getQueryLog()));
    }

    public function testItCanRetrieveRegisteredFeatures(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => false);
        Feature::define('baz', fn () => false);
        Feature::define('1', fn () => false);

        $registered = Feature::defined();

        $this->assertSame(['foo', 'bar', 'baz', '1'], $registered);
        $this->assertCount(0, DB::getQueryLog());
    }

    public function testItCanClearTheCache(): void
    {
        Feature::define('foo', fn () => true);

        Feature::active('foo');
        Feature::flushCache();
        Feature::active('foo');

        $this->assertCount(3, DB::getQueryLog());
    }

    public function testItCanGetAllFeatures(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => false);

        $all = Feature::all();

        $this->assertSame([
            'foo' => true,
            'bar' => false,
        ], $all);
    }

    public function testItCanReevaluateFeatureState(): void
    {
        Feature::define('foo', fn () => false);
        $this->assertFalse(Feature::for('tim')->value('foo'));

        Feature::for('tim')->forget('foo');

        Feature::define('foo', fn () => true);
        $this->assertTrue(Feature::for('tim')->value('foo'));
    }

    public function testItCanPurgeFlags(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');
        Feature::for('taylor')->active('bar');

        $this->assertSame(3, DB::table('features')->count());

        Feature::purge('foo');

        $this->assertSame(1, DB::table('features')->count());

        Feature::purge('bar');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanPurgeMultipleFlagsAtOnce(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);
        Feature::define('baz', false);

        Feature::for('tim')->active('foo');
        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');
        Feature::for('taylor')->active('bar');
        Feature::for('taylor')->active('baz');

        $this->assertSame(4, DB::table('features')->count());

        Feature::purge(['foo', 'bar']);

        $this->assertSame(1, DB::table('features')->count());

        Feature::purge(['baz']);

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testRetrievingValuesAfterPurging(): void
    {
        Feature::define('foo', false);

        Feature::for('tim')->activate('foo');

        $this->assertTrue(Feature::for('tim')->active('foo'));
        $this->assertSame(1, DB::table('features')->count());

        Feature::purge('foo');

        $this->assertSame(0, DB::table('features')->count());

        $this->assertFalse(Feature::for('tim')->active('foo'));
    }

    public function testItCanPurgeAllFeatureFlags(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');
        Feature::for('taylor')->active('bar');

        $this->assertSame(3, DB::table('features')->count());

        Feature::purge();

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanCustomiseDefaultScope(): void
    {
        $scopes = [];
        Feature::define('foo', function ($scope) use (&$scopes) {
            $scopes[] = $scope;
        });

        Feature::active('foo');

        Auth::login($user = new User(['id' => 1]));
        Feature::active('foo');

        Feature::resolveScopeUsing(fn () => 'bar');
        Feature::active('foo');

        $this->assertSame([
            null,
            $user,
            'bar',
        ], $scopes);
    }

    public function testItDoesntIncludeDefaultScopeWhenNull(): void
    {
        $scopes = [];
        Feature::define('foo', function ($scope) use (&$scopes) {
            $scopes[] = $scope;
        });

        Feature::resolveScopeUsing(fn () => null);
        Feature::active('foo');

        $this->assertSame([
            null,
        ], $scopes);
    }

    public function testItCanInteractGloballyIgnoringTheDefaultScope(): void
    {
        $scopes = [];
        Feature::define('foo', function ($scope) use (&$scopes) {
            $scopes[] = $scope;

            return true;
        });
        Feature::resolveScopeUsing(fn () => 'default-scope');

        $this->assertTrue(Feature::globally()->active('foo'));

        $this->assertCount(1, $scopes);
        $this->assertInstanceOf(GlobalScope::class, $scopes[0]);
        $this->assertSame('__hypervel_global', DB::table('features')->soleValue('scope'));
    }

    public function testItDoesNotStoreUnknownFeatures(): void
    {
        Event::fake([UnknownFeatureResolved::class]);

        Feature::active('foo');
        Feature::active('foo');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanUseUnregisteredClassFeatures(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeature::class);
        $value = Feature::value(UnregisteredFeature::class);
        $registered = Feature::defined();

        $this->assertSame('unregistered-value', $value);
        $this->assertSame([UnregisteredFeature::class], $registered);
        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeature::class);

            return true;
        });
    }

    public function testItCanUseUnregisteredClassFeaturesWithResolveMethod(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeatureWithResolve::class);
        $value = Feature::value(UnregisteredFeatureWithResolve::class);
        $registered = Feature::defined();

        $this->assertSame('unregistered-value.resolve', $value);
        $this->assertSame([UnregisteredFeatureWithResolve::class], $registered);
        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithResolve::class);

            return true;
        });
    }

    public function testItCanUseUnregisteredClassFeaturesWithNameProperty(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeatureWithName::class);
        $value = Feature::value(UnregisteredFeatureWithName::class);
        $registered = Feature::defined();

        $this->assertSame('unregistered-value', $value);
        $this->assertSame(['feature-name'], $registered);
        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

            return true;
        });
    }

    public function testItCanDeleteUnregisteredClassFeaturesWithNameProperty(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeatureWithName::class);
        $this->assertSame(1, DB::table('features')->where('name', 'feature-name')->count());

        Feature::forgetDrivers();

        Feature::forget(UnregisteredFeatureWithName::class);
        $this->assertSame(0, DB::table('features')->where('name', 'feature-name')->count());

        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

            return true;
        });
    }

    public function testItCanActivateUnregisteredClassFeaturesWithNameProperty(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::activate(UnregisteredFeatureWithName::class, 'expected-value');
        $this->assertSame(1, DB::table('features')->where('name', 'feature-name')->where('value', '"expected-value"')->count());

        Feature::forgetDrivers();

        Feature::forget(UnregisteredFeatureWithName::class);
        $this->assertSame(0, DB::table('features')->where('name', 'feature-name')->where('value', '"expected-value"')->count());

        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithName::class);

            return true;
        });
    }

    public function testItCanUseUnregisteredClassFeaturesWithNameAttribute(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeatureWithNameAttribute::class);
        $value = Feature::value(UnregisteredFeatureWithNameAttribute::class);
        $registered = Feature::defined();

        $this->assertSame('unregistered-value', $value);
        $this->assertSame(['feature-name-attribute'], $registered);
        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 1);
        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithNameAttribute::class);

            return true;
        });
    }

    public function testItCanDeleteUnregisteredClassFeaturesWithNameAttribute(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::value(UnregisteredFeatureWithNameAttribute::class);
        $this->assertSame(1, DB::table('features')->where('name', 'feature-name-attribute')->count());

        Feature::forgetDrivers();

        Feature::forget(UnregisteredFeatureWithNameAttribute::class);
        $this->assertSame(0, DB::table('features')->where('name', 'feature-name-attribute')->count());

        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);

        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithNameAttribute::class);

            return true;
        });
    }

    public function testItCanActivateUnregisteredClassFeaturesWithNameAttribute(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        Feature::activate(UnregisteredFeatureWithNameAttribute::class, 'expected-value');
        $this->assertSame(1, DB::table('features')->where('name', 'feature-name-attribute')->where('value', '"expected-value"')->count());

        Feature::forgetDrivers();

        Feature::forget(UnregisteredFeatureWithNameAttribute::class);
        $this->assertSame(0, DB::table('features')->where('name', 'feature-name-attribute')->where('value', '"expected-value"')->count());

        Event::assertDispatched(DynamicallyRegisteringFeatureClass::class, 2);

        Event::assertDispatched(function (DynamicallyRegisteringFeatureClass $event) {
            $this->assertSame($event->feature, UnregisteredFeatureWithNameAttribute::class);

            return true;
        });
    }

    public function testNameAttributeTakesPrecedenceOverNameProperty(): void
    {
        Event::fake([DynamicallyRegisteringFeatureClass::class]);

        $value = Feature::value(FeatureWithNameAttributeAndProperty::class);
        $registered = Feature::defined();

        $this->assertSame('from-attribute-and-property', $value);
        $this->assertSame(['attribute-name'], $registered);
    }

    public function testItCanConditionallyExecuteCodeBlockForInactiveFeature(): void
    {
        $active = $inactive = null;

        Feature::when(
            'foo',
            function () use (&$active) {
                $active = true;
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        $this->assertNull($active);
        $this->assertTrue($inactive);
    }

    public function testItCanConditionallyExecuteCodeBlockForActiveFeature(): void
    {
        $active = $inactive = null;
        Feature::activate('foo');

        Feature::when(
            'foo',
            function () use (&$active) {
                $active = true;
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        $this->assertTrue($active);
        $this->assertNull($inactive);
    }

    public function testItReceivesValueForFeatureInConditionalCodeExecution(): void
    {
        $active = $inactive = null;
        Feature::activate('foo', ['hello' => 'world']);

        Feature::when(
            'foo',
            function ($value) use (&$active) {
                $active = $value;
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        $this->assertSame(['hello' => 'world'], $active);
        $this->assertNull($inactive);
    }

    public function testConditionallyExecutingCodeRespectsScope(): void
    {
        $active = $inactive = null;
        Feature::for('tim')->activate('foo');

        Feature::when(
            'foo',
            function () use (&$active) {
                $active = true;
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        $this->assertNull($active);
        $this->assertTrue($inactive);

        $active = $inactive = null;

        Feature::for('tim')->when(
            'foo',
            function () use (&$active) {
                $active = true;
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        $this->assertTrue($active);
        $this->assertNull($inactive);
    }

    public function testConditionalClosuresReceiveCurrentFeatureInteraction(): void
    {
        $active = $inactive = null;
        Feature::for('tim')->activate('foo', ['hello' => 'tim']);

        Feature::for('tim')->when(
            'foo',
            function ($value, $feature) {
                $feature->deactivate('foo');
            },
            function () use (&$inactive) {
                $inactive = true;
            },
        );

        Feature::flushCache();
        $this->assertFalse(Feature::for('tim')->active('foo'));
        $this->assertNull($inactive);
    }

    public function testItCanSetForAll(): void
    {
        Feature::define('foo', fn () => false);

        Feature::for('tim')->activate('foo');
        Feature::for('taylor')->activate('foo');

        $this->assertTrue(Feature::for('tim')->value('foo'));
        $this->assertTrue(Feature::for('taylor')->value('foo'));
        $this->assertTrue(Feature::getDriver()->get('foo', 'tim'));
        $this->assertTrue(Feature::getDriver()->get('foo', 'taylor'));

        Feature::deactivateForEveryone('foo');

        $this->assertFalse(Feature::for('tim')->value('foo'));
        $this->assertFalse(Feature::for('taylor')->value('foo'));
        $this->assertFalse(Feature::getDriver()->get('foo', 'tim'));
        $this->assertFalse(Feature::getDriver()->get('foo', 'taylor'));

        Feature::activateForEveryone('foo');

        $this->assertTrue(Feature::for('tim')->value('foo'));
        $this->assertTrue(Feature::for('taylor')->value('foo'));
        $this->assertTrue(Feature::getDriver()->get('foo', 'tim'));
        $this->assertTrue(Feature::getDriver()->get('foo', 'taylor'));
    }

    public function testItCanAutoRegisterFeatureClasses(): void
    {
        Feature::define('marketing-design', 'marketing-design-value');
        Feature::discover('Hypervel\Tests\Pennant\Fixtures\Features', __DIR__ . '/../Fixtures/Features');

        $all = Feature::all();

        $this->assertSame([
            'marketing-design' => 'marketing-design-value',
            'Hypervel\Tests\Pennant\Fixtures\Features\NewApi' => 'new-api-value',
        ], $all);
    }

    public function testItHandlesMultiscopeChecks(): void
    {
        Feature::define('foo', false);

        $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        Feature::for('tim')->activate('foo');

        $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        Feature::for('taylor')->activate('foo');

        $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        Feature::for('tim')->activate('bar');

        $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
        $this->assertFalse($result);

        Feature::for('taylor')->activate('bar');

        $result = Feature::for(['tim', 'taylor'])->allAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreInactive(['foo', 'bar']);
        $this->assertFalse($result);

        $result = Feature::for(['tim', 'taylor'])->someAreActive(['foo', 'bar']);
        $this->assertTrue($result);

        $result = Feature::for(['tim', 'taylor'])->allAreActive(['foo', 'bar']);
        $this->assertTrue($result);
    }

    public function testBulkInsertAddsTimestamps(): void
    {
        Feature::define('foo', true);

        Feature::values(['foo']);
        $record = DB::table('features')->first();

        $this->assertNotNull($record->updated_at);
        $this->assertNotNull($record->created_at);
    }

    public function testStoresMayBeConfigured(): void
    {
        $this->app->make('config')->set('database.connections.foo_connection', $this->app->make('config')->get('database.connections.testing'));
        $this->app->make('config')->set('database.connections.bar_connection', $this->app->make('config')->get('database.connections.testing'));
        $this->app->make('config')->set('pennant.stores.foo', [
            'driver' => 'database',
            'connection' => 'foo_connection',
            'table' => 'foo_features',
        ]);
        $this->app->make('config')->set('pennant.stores.bar', [
            'driver' => 'database',
            'connection' => 'bar_connection',
            'table' => 'bar_features',
        ]);
        $connectionResolver = function () {
            return $this->newQuery()->connection->getName();
        };
        $tableResolver = function () {
            return $this->newQuery()->from;
        };

        $driver = Feature::store('foo')->getDriver();
        $this->assertSame('foo_connection', $connectionResolver->bindTo($driver, $driver)());
        $this->assertSame('foo_features', $tableResolver->bindTo($driver, $driver)());

        $driver = Feature::store('bar')->getDriver();
        $this->assertSame('bar_connection', $connectionResolver->bindTo($driver, $driver)());
        $this->assertSame('bar_features', $tableResolver->bindTo($driver, $driver)());
    }

    #[DataProvider('unsetConnections')]
    public function testItUsesDefaultConnectionIfNull(?string $connection): void
    {
        Config::set('database.connections.other', config('database.connections.' . config('database.default')));
        Config::set('pennant.stores.database.connection', $connection);

        $migration = new class extends PennantMigration {};
        $migrator = $this->app->make('migrator');

        $this->assertNull($migration->getConnection());
        $this->assertSame('other', $migrator->usingConnection('other', fn () => $migrator->resolveConnection($migration->getConnection())->getName()));
    }

    public static function unsetConnections(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    public function testItUsesThePennantConnectionForMigrations(): void
    {
        Config::set('pennant.stores.database.connection', 'pennant');

        $this->assertSame('pennant', (new class extends PennantMigration {})->getConnection());
    }

    #[DataProvider('readConnections')]
    public function testItRejectsReadConnections(Closure $read): void
    {
        $connection = DB::getDefaultConnection() . '::read';
        Feature::define('foo', fn () => true);

        try {
            $read($connection);
            $this->fail('The read connection should have been rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame("Pennant can't use the read connection [{$connection}]. Use its base or write connection instead.", $exception->getMessage());
        }

        $this->assertCount(0, DB::getQueryLog());
    }

    public static function readConnections(): array
    {
        return [
            'configured' => [function (string $connection) {
                Config::set('pennant.stores.replica', ['driver' => 'database', 'connection' => $connection]);

                Feature::store('replica')->active('foo');
            }],
            'runtime default' => [fn (string $connection) => DB::usingConnection($connection, fn () => Feature::active('foo'))],
        ];
    }

    public function testItDispatchesEventsWhenPurgingFeatures(): void
    {
        Event::fake([FeaturesPurged::class]);

        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);

        Feature::purge(['foo', 'bar', 'baz']);

        Event::assertDispatchedTimes(FeaturesPurged::class, 1);
        Event::assertDispatched(function (FeaturesPurged $event) {
            return $event->features === ['foo', 'bar', 'baz'];
        });
    }

    public function testItDispatchesEventsWhenPurgingAllFeatures(): void
    {
        Event::fake([AllFeaturesPurged::class]);

        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);

        Feature::purge();

        Event::assertDispatchedTimes(AllFeaturesPurged::class, 1);
    }

    public function testItDispatchesEventsWhenUpdatingAScopedFeature(): void
    {
        Event::fake([FeatureUpdated::class]);

        Feature::define('foo', fn () => false);

        Feature::for('tim')->activate('foo');

        Event::assertDispatchedTimes(FeatureUpdated::class, 1);
        Event::assertDispatched(function (FeatureUpdated $event) {
            return $event->feature === 'foo'
                && $event->scope === 'tim'
                && $event->value === true;
        });
    }

    public function testItDispatchesEventsWhenUpdatingAFeatureForAllScopes(): void
    {
        Event::fake([FeatureUpdatedForAllScopes::class]);

        Feature::define('foo', fn () => false);

        Feature::activateForEveryone('foo', true);

        Event::assertDispatchedTimes(FeatureUpdatedForAllScopes::class, 1);
        Event::assertDispatched(function (FeatureUpdatedForAllScopes $event) {
            return $event->feature === 'foo'
                && $event->value === true;
        });
    }

    public function testItDispatchesEventsWhenDeletingAFeatureValue(): void
    {
        Event::fake([FeatureDeleted::class]);

        Feature::define('foo', fn () => false);

        Feature::for('tim')->forget('foo');

        Event::assertDispatchedTimes(FeatureDeleted::class, 1);
        Event::assertDispatched(function (FeatureDeleted $event) {
            return $event->feature === 'foo'
                && $event->scope === 'tim';
        });
    }

    public function testItCanUseEloquentMorphMapForScopeSerialization(): void
    {
        $model = new User(['id' => 6]);
        Relation::morphMap([
            'user-morph' => $model::class,
        ]);
        $scopes = [];
        Feature::define('foo', fn () => true);

        Feature::useMorphMap();
        Feature::for($model)->active('foo');

        $this->assertDatabaseHas('features', [
            'name' => 'foo',
            'scope' => 'user-morph|6',
            'value' => 'true',
        ]);
    }

    public function testItRespectsUpdatedConnectionConfiguration(): void
    {
        Feature::flushCache();
        Config::set('pennant.stores.database.connection', null);
        Feature::store('database')->active('feature-name');

        // Stores read their configuration when they're built.
        Feature::forgetDriver('database');
        Config::set('pennant.stores.database.connection', 'xxxx');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Database connection [xxxx] not configured.');
        Feature::store('database')->active('feature-name');
    }

    public function testItCanListStoredFeatures(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);

        Feature::for('tim')->active('bar');
        DB::table('features')->insert([
            'name' => 'baz',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertSame(Feature::stored(), ['bar', 'baz']);
    }

    public function testItRetries3TimesAndThenFails(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);
        $insertAttempts = $this->competeForEveryInsert(['foo', 'bar'], 'tim');

        try {
            Feature::for('tim')->loadMissing(['foo', 'bar']);
            $this->fail('Should have failed.');
        } catch (Exception $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertSame('Unable to insert feature values into the database.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame(3, $insertAttempts->count);
    }

    public function testItOnlyRetriesOnConflicts(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);
        $insertAttempts = $this->storeWinnerBeforeInsert('foo', 'tim', 'winner');

        $values = Feature::for('tim')->loadMissing(['foo', 'bar']);

        $this->assertSame(['foo' => ['winner'], 'bar' => [true]], $values);
        $this->assertSame('winner', Feature::for('tim')->value('foo'));
        $this->assertSame(1, $insertAttempts->count);
    }

    public function testItRetries3TimesAndThenFailsForIndividualQueries(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);
        $insertAttempts = $this->competeForEveryInsert(['foo'], 'tim');

        try {
            Feature::driver('database')->get('foo', 'tim');
            $this->fail('Should have failed.');
        } catch (Exception $e) {
            $this->assertInstanceOf(RuntimeException::class, $e);
            $this->assertSame('Unable to insert feature values into the database.', $e->getMessage());
            $this->assertNull($e->getPrevious());
        }

        $this->assertSame(3, $insertAttempts->count);
    }

    public function testItOnlyRetriesOnConflictsForIndividualQueries(): void
    {
        // The winner's JSON matches the losing value's, but the stored value is still what's returned.
        Feature::define('foo', fn () => (object) ['enabled' => true]);
        Feature::define('bar', fn () => true);
        $insertAttempts = $this->storeWinnerBeforeInsert('foo', 'tim', ['enabled' => true]);

        $this->assertSame(['enabled' => true], Feature::driver('database')->get('foo', 'tim'));
        $this->assertSame(1, $insertAttempts->count);
    }

    /**
     * Store a competing row after the first read, as a concurrent request would, so the insert loses to it.
     */
    protected function storeWinnerBeforeInsert(string $feature, string $scope, mixed $value): stdClass
    {
        $insertAttempts = (object) ['count' => 0];
        $stored = false;

        DB::listen(function (QueryExecuted $event) use ($feature, $scope, $value, $insertAttempts, &$stored) {
            if (! $stored && Str::startsWith($event->sql, 'select')) {
                $stored = true;

                DB::table('features')->insert([
                    'name' => $feature,
                    'scope' => $scope,
                    'value' => json_encode($value),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } elseif (Str::startsWith($event->sql, 'insert or ignore into "features"')) {
                ++$insertAttempts->count;
            }
        });

        return $insertAttempts;
    }

    /**
     * Store competing rows after every read and delete them after every insert, as a concurrent purge would.
     *
     * Each insert is then ignored, and the reread that follows finds nothing.
     *
     * @param list<string> $features
     */
    protected function competeForEveryInsert(array $features, string $scope): stdClass
    {
        $insertAttempts = (object) ['count' => 0];
        $competing = false;

        DB::listen(function (QueryExecuted $event) use ($features, $scope, $insertAttempts, &$competing) {
            if ($competing) {
                return;
            }

            $competing = true;

            if (Str::startsWith($event->sql, 'select')) {
                DB::table('features')->insert(array_map(fn (string $feature) => [
                    'name' => $feature,
                    'scope' => $scope,
                    'value' => '"competitor"',
                    'created_at' => now(),
                    'updated_at' => now(),
                ], $features));
            } elseif (Str::startsWith($event->sql, 'insert or ignore into "features"')) {
                ++$insertAttempts->count;

                DB::table('features')->delete();
            }

            $competing = false;
        });

        return $insertAttempts;
    }

    public function testItCanInterceptFeatureValuesUsingBeforeHook(): void
    {
        $queries = 0;
        FeatureWithBeforeHook::$before = fn ($scope) => 'before';
        Feature::define(FeatureWithBeforeHook::class);
        Feature::activate(FeatureWithBeforeHook::class, 'stored-value');
        Feature::flushCache();
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });

        $value = Feature::for(null)->value(FeatureWithBeforeHook::class);

        $this->assertSame('before', $value);
        $this->assertSame(0, $queries);

        // A class name defined as a value is a constant, so the class's before hook doesn't run.
        Feature::define('class-name-value', FeatureWithBeforeHook::class);

        $this->assertSame(FeatureWithBeforeHook::class, Feature::value('class-name-value'));
    }

    public function testItCanMergesBeforeWithResolvedFeatures(): void
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });
        FeatureWithBeforeHook::$before = fn ($scope) => ['before' => 'value'];
        Feature::define('foo', 'bar');
        Feature::define(FeatureWithBeforeHook::class);

        $values = Feature::for('user:1')->all();

        $this->assertSame([
            'foo' => 'bar',
            'Hypervel\Tests\Pennant\Feature\FeatureWithBeforeHook' => ['before' => 'value'],
        ], $values);
        $this->assertSame(2, $queries);
    }

    public function testItCanGetFeaturesWithBeforeHook(): void
    {
        $queries = 0;
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });
        FeatureWithBeforeHook::$before = fn ($scope) => ['before' => 'value'];
        FeatureWithBeforeHookAndCustomName::$before = fn ($scope) => ['before' => 'value 2'];

        $value = Feature::get(FeatureWithBeforeHook::class, null);

        $this->assertSame(['before' => 'value'], $value);
        $this->assertSame(0, $queries);

        $value = Feature::get(FeatureWithBeforeHookAndCustomName::class, null);

        $this->assertSame(['before' => 'value 2'], $value);
        $this->assertSame(0, $queries);

        $value = Feature::get('feature-with-before-hook-and-custom-name', null);

        $this->assertSame(['before' => 'value 2'], $value);
        $this->assertSame(0, $queries);
    }

    public function testItHandlesNullScopeForBeforeHook(): void
    {
        Event::fake(UnexpectedNullScopeEncountered::class);
        $queries = 0;
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });
        FeatureWithTypedBeforeHook::$before = fn ($scope) => 'before-value';
        Feature::define(FeatureWithTypedBeforeHook::class);

        $values = Feature::for(null)->value(FeatureWithTypedBeforeHook::class);

        $this->assertSame('feature-value', $values);
        $this->assertSame(2, $queries);

        Feature::flushCache();

        $value = Feature::get(FeatureWithTypedBeforeHook::class, null);

        $this->assertSame('feature-value', $value);
        $this->assertSame(3, $queries);
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 2);
    }

    public function testRedefiningAFeatureRechecksWhetherItsBeforeHookAcceptsNullScope(): void
    {
        Event::fake(UnexpectedNullScopeEncountered::class);
        FeatureWithBeforeHookAndCustomName::$before = fn () => 'before-value';
        Feature::define(FeatureWithBeforeHookAndCustomName::class);

        $this->assertSame('before-value', Feature::for(null)->value('feature-with-before-hook-and-custom-name'));

        Feature::define(FeatureWithTypedBeforeHookAndCustomName::class);
        Feature::flushCache();

        $this->assertSame('feature-value', Feature::for(null)->value('feature-with-before-hook-and-custom-name'));
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 1);
    }

    public function testItCanUseBeforeHookWhenUsingFeatureNameProperty(): void
    {
        $queries = 0;
        FeatureWithBeforeHookAndCustomName::$before = fn ($scope) => 'before';
        Feature::define(FeatureWithBeforeHookAndCustomName::class);
        Feature::activate(FeatureWithBeforeHookAndCustomName::class, 'stored-value');
        Feature::flushCache();
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });

        $value = Feature::for(null)->value(FeatureWithBeforeHookAndCustomName::class);

        $this->assertSame('before', $value);
        $this->assertSame(0, $queries);
    }

    public function testItCanUseBeforeHookWhenUsingFeatureNamePropertyOnADynamicallyRegisteredFeature(): void
    {
        $queries = 0;
        FeatureWithBeforeHookAndCustomName::$before = fn ($scope) => 'before';
        Feature::activate(FeatureWithBeforeHookAndCustomName::class, 'stored-value');
        Feature::activate('blah', 'stored-value');
        Feature::flushCache();
        DB::listen(function (QueryExecuted $event) use (&$queries) {
            ++$queries;
        });

        $value = Feature::for(null)->value(FeatureWithBeforeHookAndCustomName::class);

        $this->assertSame('before', $value);
        $this->assertSame(0, $queries);
    }

    public function testItMaintainsScopeFeatureKeys(): void
    {
        $count = 0;
        FeatureWithBeforeHook::$before = function ($scope) use (&$count) {
            if ($scope === 'user:2') {
                return null;
            }

            ++$count;

            return "before-value-{$count}";
        };
        Feature::define(FeatureWithBeforeHook::class);

        $values = Feature::getAll([
            FeatureWithBeforeHook::class => ['user:1', 'user:2', 'user:3'],
        ]);

        $this->assertSame([
            'Hypervel\Tests\Pennant\Feature\FeatureWithBeforeHook' => ['before-value-1', 'feature-value', 'before-value-2'],
        ], $values);
    }

    public function testItKeysByFeatureName(): void
    {
        Feature::define(FeatureWithName::class);
        Feature::define(FeatureWithoutName::class);

        $this->assertSame([
            'feature-with-name' => 'feature-with-name-value',
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => 'feature-without-name-value',
        ], Feature::all());

        $this->assertSame([
            'feature-with-name' => 'feature-with-name-value',
            'feature-with-name' => 'feature-with-name-value',
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => 'feature-without-name-value',
        ], Feature::values([
            'feature-with-name',
            FeatureWithName::class,
            FeatureWithoutName::class,
        ]));

        $this->assertSame([
            'feature-with-name' => ['feature-with-name-value'],
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => ['feature-without-name-value'],
        ], Feature::load([
            'feature-with-name',
            FeatureWithoutName::class,
        ]));

        $this->assertSame([
            'feature-with-name' => ['feature-with-name-value'],
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => ['feature-without-name-value'],
        ], Feature::load([
            FeatureWithName::class,
            FeatureWithoutName::class,
        ]));

        Feature::flushCache();

        $this->assertSame([
            'feature-with-name' => ['feature-with-name-value'],
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => ['feature-without-name-value'],
        ], Feature::loadMissing([
            'feature-with-name',
            FeatureWithoutName::class,
        ]));

        Feature::flushCache();

        $this->assertSame([
            'feature-with-name' => ['feature-with-name-value'],
            'Hypervel\Tests\Pennant\Feature\FeatureWithoutName' => ['feature-without-name-value'],
        ], Feature::loadMissing([
            FeatureWithName::class,
            FeatureWithoutName::class,
        ]));
    }

    public function testItCanLoadAllFeaturesForScope(): void
    {
        Feature::define('bar', fn ($scope) => $scope === 'taylor');
        Feature::define('foo', fn ($scope) => $scope === 'tim');

        Feature::for(['tim', 'taylor'])->loadAll();

        $records = DB::table('features')->orderBy('scope')->orderBy('name')->get(['name', 'scope', 'value'])->all();
        $this->assertCount(4, $records);
        $this->assertEquals((object) [
            'scope' => 'taylor',
            'value' => 'true',
            'name' => 'bar',
        ], $records[0]);
        $this->assertEquals((object) [
            'scope' => 'taylor',
            'value' => 'false',
            'name' => 'foo',
        ], $records[1]);
        $this->assertEquals((object) [
            'scope' => 'tim',
            'value' => 'false',
            'name' => 'bar',
        ], $records[2]);
        $this->assertEquals((object) [
            'scope' => 'tim',
            'value' => 'true',
            'name' => 'foo',
        ], $records[3]);
    }

    public function testItCanLoadAllFeaturesForScopesOfDifferentTypes(): void
    {
        Feature::define('user', fn (User $user) => 'user');
        Feature::define('team', fn (Team $team) => 'team');

        $this->assertSame(
            ['user' => [0 => 'user'], 'team' => [1 => 'team']],
            Feature::for([new User(['id' => 1]), new Team(['id' => 1])])->loadAll(),
        );
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testCanRetrieveAllFeaturesForDifferingScopeTypes(): void
    {
        Feature::define('user', fn (User $user) => 1);
        Feature::define('nullable-user', fn (?User $user) => 2);
        Feature::define('team', fn (Team $team) => 3);
        Feature::define('nullable-team', fn (?Team $team) => 4);
        Feature::define('mixed', fn (mixed $v) => 5);
        Feature::define('none', fn ($v) => 6);
        Feature::define('array', fn (array $t) => 7);
        Feature::define('string', fn (string $str) => 8);
        Feature::define('team-or-user', fn (Team|User $model) => 9);

        $features = Feature::for(new User(['id' => 1]))->all();
        $this->assertSame([
            'user' => 1,
            'nullable-user' => 2,
            'mixed' => 5,
            'none' => 6,
            'team-or-user' => 9,
        ], $features);
        $this->assertCount(2, DB::getQueryLog());
        $this->assertCount(5, DB::table('features')->get()); // query

        $features = Feature::for(new Team(['id' => 1]))->all();
        $this->assertSame([
            'team' => 3,
            'nullable-team' => 4,
            'mixed' => 5,
            'none' => 6,
            'team-or-user' => 9,
        ], $features);
        $this->assertCount(5, DB::getQueryLog());
        $this->assertCount(10, DB::table('features')->get()); // query

        $features = Feature::for('scope')->all();
        $this->assertSame([
            'mixed' => 5,
            'none' => 6,
            'string' => 8,
        ], $features);
        $this->assertCount(8, DB::getQueryLog());
        $this->assertCount(13, DB::table('features')->get());
    }

    public function testCanRetrieveScalarValuesWithoutInMemoryCache(): void
    {
        $data = [
            'one' => 1,
            'ten' => 10,
            'zero' => 0,
            'minus-one' => -1,
            'minus-ten' => -10,
            'one-point-five' => 1.5,
            'ten-point-five' => 10.5,
            'zero-point-five' => 0.5,
            'minus-one-point-five' => -1.5,
            'minus-ten-point-five' => -10.5,
            'bool-true' => true,
            'bool-false' => false,
            'null' => null,
        ];

        $user = new User(['id' => 1]);

        foreach ($data as $name => $expectedValue) {
            $feature = 'scalar-feature:' . $name;
            Feature::define($feature, fn (User $user) => $expectedValue);

            $generated = Feature::for($user)->value($feature);

            Feature::flushCache();

            $retrieved = Feature::for($user)->value($feature);

            $this->assertEquals($expectedValue, $generated);
            $this->assertEquals($expectedValue, $retrieved);
        }
    }

    public function testItDispatchesEventsWhenActivatingMultipleFeaturesAndScopes(): void
    {
        Event::fake([FeatureUpdated::class]);

        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);

        Feature::for([$first, $second])->activate(['foo', 'bar']);

        Event::assertDispatchedTimes(FeatureUpdated::class, 4);

        $events = [];
        Event::assertDispatched(function (FeatureUpdated $event) use (&$events) {
            $events[] = [
                'feature' => $event->feature,
                'scope' => $event->scope,
                'value' => $event->value,
            ];

            return true;
        });

        $this->assertCount(4, $events);
        $this->assertContains([
            'feature' => 'foo',
            'scope' => $first,
            'value' => true,
        ], $events);
        $this->assertContains([
            'feature' => 'foo',
            'scope' => $second,
            'value' => true,
        ], $events);
        $this->assertContains([
            'feature' => 'bar',
            'scope' => $first,
            'value' => true,
        ], $events);
        $this->assertContains([
            'feature' => 'bar',
            'scope' => $second,
            'value' => true,
        ], $events);
    }

    public function testItDispatchesEventsWhenDeactivatingMultipleFeaturesAndScopes(): void
    {
        Event::fake([FeatureUpdated::class]);

        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);

        Feature::for([$first, $second])->deactivate(['foo', 'bar']);

        Event::assertDispatchedTimes(FeatureUpdated::class, 4);

        $events = [];
        Event::assertDispatched(function (FeatureUpdated $event) use (&$events) {
            $events[] = [
                'feature' => $event->feature,
                'scope' => $event->scope,
                'value' => $event->value,
            ];

            return true;
        });

        $this->assertCount(4, $events);
        $this->assertContains([
            'feature' => 'foo',
            'scope' => $first,
            'value' => false,
        ], $events);
        $this->assertContains([
            'feature' => 'foo',
            'scope' => $second,
            'value' => false,
        ], $events);
        $this->assertContains([
            'feature' => 'bar',
            'scope' => $first,
            'value' => false,
        ], $events);
        $this->assertContains([
            'feature' => 'bar',
            'scope' => $second,
            'value' => false,
        ], $events);
    }

    public function testItUsesSingleQueryWhenActivatingMultipleFeaturesAndScopes(): void
    {
        DB::enableQueryLog();

        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);

        Feature::for([$first, $second])->activate(['foo', 'bar']);

        $this->assertCount(1, DB::getQueryLog());
    }

    public function testItKeepsTheLastValueOfARepeatedFeatureAndScope(): void
    {
        Feature::store()->setAll([
            ['feature' => 'foo', 'scope' => 'tim', 'value' => 'first'],
            ['feature' => 'foo', 'scope' => 'tim', 'value' => 'last'],
        ]);
        Feature::flushCache();

        $this->assertSame('last', Feature::for('tim')->value('foo'));
    }
}

class UnregisteredFeature
{
    public function __invoke(): string
    {
        return 'unregistered-value';
    }
}

class UnregisteredFeatureWithResolve
{
    public function resolve(): string
    {
        return 'unregistered-value.resolve';
    }
}

class UnregisteredFeatureWithName
{
    public string $name = 'feature-name';

    public function __invoke(): string
    {
        return 'unregistered-value';
    }
}

class FeatureWithBeforeHook
{
    public static ?Closure $before = null;

    public function resolve(): string
    {
        return 'feature-value';
    }

    public function before(): mixed
    {
        return (static::$before)(...func_get_args());
    }
}

class FeatureWithBeforeHookAndCustomName
{
    public string $name = 'feature-with-before-hook-and-custom-name';

    public static ?Closure $before = null;

    public function resolve(): string
    {
        return 'feature-value';
    }

    public function before(): mixed
    {
        return (static::$before)(...func_get_args());
    }
}

class FeatureWithTypedBeforeHookAndCustomName
{
    public string $name = 'feature-with-before-hook-and-custom-name';

    public function resolve(): string
    {
        return 'feature-value';
    }

    public function before(string $scope): string
    {
        return 'before-value';
    }
}

class FeatureWithTypedBeforeHook
{
    public static ?Closure $before = null;

    public function resolve(): string
    {
        return 'feature-value';
    }

    public function before(string $scope): mixed
    {
        return (static::$before)(...func_get_args());
    }
}

class FeatureWithoutName
{
    public function __invoke(): string
    {
        return 'feature-without-name-value';
    }
}

class FeatureWithName
{
    public string $name = 'feature-with-name';

    public function __invoke(): string
    {
        return 'feature-with-name-value';
    }
}

#[Name('feature-name-attribute')]
class UnregisteredFeatureWithNameAttribute
{
    public function __invoke(): string
    {
        return 'unregistered-value';
    }
}

#[Name('attribute-name')]
class FeatureWithNameAttributeAndProperty
{
    public string $name = 'property-name';

    public function __invoke(): string
    {
        return 'from-attribute-and-property';
    }
}
