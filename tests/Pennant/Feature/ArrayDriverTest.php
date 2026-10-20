<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\Attributes\Name;
use Hypervel\Pennant\Contracts\FeatureScopeable;
use Hypervel\Pennant\Events\AllFeaturesPurged;
use Hypervel\Pennant\Events\FeatureDeleted;
use Hypervel\Pennant\Events\FeatureResolved;
use Hypervel\Pennant\Events\FeaturesPurged;
use Hypervel\Pennant\Events\FeatureUpdated;
use Hypervel\Pennant\Events\FeatureUpdatedForAllScopes;
use Hypervel\Pennant\Events\UnexpectedNullScopeEncountered;
use Hypervel\Pennant\Events\UnknownFeatureResolved;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\GlobalScope;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Lottery;
use Hypervel\Tests\Pennant\Fixtures\User;
use Hypervel\Tests\Pennant\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ArrayDriverTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'array');
    }

    public function testItDefaultsToFalseForUnknownValues(): void
    {
        $result = Feature::active('foo');

        $this->assertFalse($result);
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
    }

    public function testItCanProgramaticallyActivateAndDeativateFeatures(): void
    {
        Feature::activate('foo');
        $this->assertTrue(Feature::active('foo'));

        Feature::deactivate('foo');
        $this->assertFalse(Feature::active('foo'));

        Feature::activate('foo');
        $this->assertTrue(Feature::active('foo'));
    }

    public function testItDispatchesEventsWhenResolvingFeatureIntoMemory(): void
    {
        Event::fake([FeatureResolved::class]);
        Feature::define('foo', fn () => true);

        Feature::active('foo');
        Feature::active('foo');

        Event::assertDispatchedTimes(FeatureResolved::class, 1);
        Event::assertDispatched(function (FeatureResolved $event) {
            return $event->feature === 'foo' && $event->scope === null;
        });
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
        $this->assertFalse(Feature::active('bar'));

        Feature::activate(['bar', 'baz']);

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::active('bar'));
        $this->assertTrue(Feature::active('bar'));
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
    }

    public function testItCanActivateAndDeactivateFeaturesWithScope(): void
    {
        $first = new User(['id' => 1]);
        $second = new User(['id' => 2]);

        Feature::for($first)->activate('foo');

        $this->assertFalse(Feature::active('foo'));
        $this->assertTrue(Feature::for($first)->active('foo'));
        $this->assertFalse(Feature::for($second)->active('foo'));
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
    }

    public function testNullIsSameAsNoScope(): void
    {
        Feature::activate('foo');

        $this->assertTrue(Feature::for(null)->active('foo'));
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
    }

    public function testScopeCanBeStringsLikeEmailAddresses(): void
    {
        Feature::for('tim@hypervel.com')->activate('foo');

        $this->assertFalse(Feature::for('james@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for('tim@hypervel.com')->active('foo'));
    }

    public function testItCanHandleFeatureScopeableObjects(): void
    {
        $scopeable = fn () => new class extends User implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'tim@hypervel.com';
            }
        };

        Feature::for($scopeable())->activate('foo');

        $this->assertFalse(Feature::for('james@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for('tim@hypervel.com')->active('foo'));
        $this->assertTrue(Feature::for($scopeable())->active('foo'));
    }

    // The resolvers below return null, which is stored like any other value, so loading
    // again doesn't resolve again.
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

        Feature::getAll([
            'foo' => [1, 2, 3],
            'bar' => [2],
        ]);
        $this->assertSame(4, $called['foo']);
        $this->assertSame(2, $called['bar']);

        Feature::for([1, 2, 3])->active('foo');
        Feature::for([2])->active('bar');
        $this->assertSame(4, $called['foo']);
        $this->assertSame(2, $called['bar']);
    }

    public function testItThrowsWhenCallingValueWithMultipleScope(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('It is not possible to retrieve the values for multiple scopes.');

        Feature::for([1, 2, 3])->value('foo');
    }

    public function testItCanRetriveValueForMultipleScopes(): void
    {
        Feature::define('foo', fn ($scope) => $scope);

        $values = Feature::for(1)->values(['foo']);

        $this->assertSame([
            'foo' => 1,
        ], $values);
    }

    public function testItCanRetriveValueForMultipleScopesAndFeatures(): void
    {
        Feature::define('foo', fn ($scope) => $scope);
        Feature::define('bar', fn ($scope) => $scope * 2);

        $values = Feature::for(2)->values(['foo', 'bar']);

        $this->assertSame([
            'foo' => 2,
            'bar' => 4,
        ], $values);
    }

    public function testItMayRegisterShorthandFeatureValues(): void
    {
        Feature::define('foo', 'value');

        $value = Feature::value('foo');

        $this->assertSame('value', $value);
    }

    public function testItCanUseLottery(): void
    {
        Feature::define('foo', Lottery::odds(1, 1));
        Feature::define('bar', Lottery::odds(0, 1));
        Feature::define('baz', fn () => Lottery::odds(0, 1));

        $this->assertTrue(Feature::value('foo'));
        $this->assertFalse(Feature::value('bar'));
        $this->assertFalse(Feature::value('baz'));
    }

    public function testItCanRetrieveRegisteredFeatures(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => false);
        Feature::define('baz', fn () => false);

        $registered = Feature::defined();

        $this->assertSame(['foo', 'bar', 'baz'], $registered);
    }

    public function testItCanClearTheCache(): void
    {
        $called = 0;
        Feature::define('foo', function () use (&$called) {
            ++$called;
        });

        Feature::active('foo');
        Feature::flushCache();
        Feature::active('foo');

        $this->assertSame(2, $called);
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

    public function testItCanRegisterFeatureViaClass(): void
    {
        Feature::define(MyFeature::class);

        $value = Feature::for('shared')->value('my-feature');

        $this->assertSame('shared-123', $value);
    }

    public function testItCanRegisterFeaturesViaClassWithoutName(): void
    {
        Feature::define(MyUnnamedFeature::class);

        $value = Feature::for('shared')->value(MyUnnamedFeature::class);

        $this->assertSame('shared-123', $value);
    }

    public function testItCanRegisterFeatureViaClassWithNameAttribute(): void
    {
        Feature::define(MyFeatureWithNameAttribute::class);

        $value = Feature::for('shared')->value('my-feature-with-name-attribute');

        $this->assertSame('shared-attribute', $value);
    }

    public function testNameAttributeTakesPrecedenceOverNameProperty(): void
    {
        Feature::define(MyFeatureWithBothNameAttributeAndProperty::class);

        $defined = Feature::defined();
        $value = Feature::for('shared')->value('attribute-takes-priority');

        $this->assertSame(['attribute-takes-priority'], $defined);
        $this->assertSame('shared-both', $value);
    }

    public function testItCanRegisterFeatureViaClassWithResolve(): void
    {
        Feature::define(MyFeatureWithResolveMethod::class);

        $value = Feature::for('shared')->value(MyFeatureWithResolveMethod::class);

        $this->assertSame('shared-resolve-123', $value);
    }

    public function testItCanReevaluateFeatureState(): void
    {
        Feature::define('foo', fn () => false);
        $this->assertFalse(Feature::for('tim')->value('foo'));

        Feature::for('tim')->forget('foo');

        Feature::define('foo', fn () => true);
        $this->assertTrue(Feature::for('tim')->value('foo'));
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

        $resolved = 0;
        Feature::resolveScopeUsing(function () use (&$resolved) {
            ++$resolved;

            return null;
        });

        Feature::getAll(['foo', 'bar']);

        $this->assertSame(1, $resolved);
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
    }

    public function testItUsesDefaultScopeForLoadingWithString(): void
    {
        Feature::define('feature', fn () => false);
        Feature::for('tim')->activate('feature');

        $loaded = Feature::load('feature');
        $this->assertSame(['feature' => [false]], $loaded);

        Feature::resolveScopeUsing(fn () => 'tim');

        $loaded = Feature::load('feature');
        $this->assertSame(['feature' => [true]], $loaded);
    }

    public function testRetrievingValuesAfterPurging(): void
    {
        Feature::define('foo', false);

        Feature::for('tim')->activate('foo');

        $this->assertTrue(Feature::for('tim')->active('foo'));

        Feature::purge('foo');

        $this->assertFalse(Feature::for('tim')->active('foo'));
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

    public function testItCanConditionallyExecuteCodeBlockProvidingClosureForOnlyActive(): void
    {
        $active = null;

        Feature::when('foo', function () use (&$active) {
            $active = true;
        });

        $this->assertNull($active);
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

    public function testItAcceptsNullScopeForParameterlessFeature(): void
    {
        Feature::define('foo', fn () => true);

        $result = Feature::for(null)->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(null)->active(MyFeatureWithNoScope::class);
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active(MyFeatureWithNoScope::class);
        $this->assertTrue($result);
    }

    public function testItAcceptsNullScopeForUntypedFeature(): void
    {
        Feature::define('foo', fn ($user) => true);

        $result = Feature::for(null)->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(null)->active(MyFeatureWithUntypedScope::class);
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active(MyFeatureWithUntypedScope::class);
        $this->assertTrue($result);
    }

    public function testItAcceptsNullScopeForMixedTypedFeature(): void
    {
        Feature::define('foo', fn (mixed $user) => true);

        $result = Feature::for(null)->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(null)->active(MyFeatureWithMixedScope::class);
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active(MyFeatureWithMixedScope::class);
        $this->assertTrue($result);
    }

    public function testItAcceptsNullScopeForNullableTypedFeature(): void
    {
        Feature::define('foo', fn (?User $user) => true);

        $result = Feature::for(null)->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active('foo');
        $this->assertTrue($result);

        $result = Feature::for(null)->active(MyFeatureWithNullableScope::class);
        $this->assertTrue($result);

        $result = Feature::for(new User(['id' => 1]))->active(MyFeatureWithNullableScope::class);
        $this->assertTrue($result);
    }

    public function testItGracefullyHandlesNullScopeForNonNullableFeature(): void
    {
        Event::fake([UnexpectedNullScopeEncountered::class]);
        Feature::define('foo', function (User $user) {
            return true;
        });

        $result = Feature::for(null)->active('foo');
        $this->assertFalse($result);
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 1);
        Event::assertDispatched(function (UnexpectedNullScopeEncountered $event) {
            return $event->feature === 'foo';
        });

        $result = Feature::for(new User(['id' => 1]))->active('foo');
        $this->assertTrue($result);
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 1);

        $result = Feature::for(null)->active(MyFeatureWithUserScope::class);
        $this->assertFalse($result);
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 2);
        Event::assertDispatched(function (UnexpectedNullScopeEncountered $event) {
            return $event->feature === MyFeatureWithUserScope::class;
        });

        $result = Feature::for(new User(['id' => 1]))->active(MyFeatureWithUserScope::class);
        $this->assertTrue($result);
        Event::assertDispatchedTimes(UnexpectedNullScopeEncountered::class, 2);
    }

    public function testItDoesNotInterpretArrayAsCallable(): void
    {
        $class = new class {
            public function foo(): string
            {
                return 'xxxx';
            }

            public function bar(string $value): string
            {
                return $value;
            }
        };
        Feature::define('foo', [$class, 'foo']);

        $result = Feature::value('foo');

        $this->assertSame([$class, 'foo'], $result);

        // Callable-looking values are constants, so a signature that rejects the scope doesn't hide them.
        Feature::define('bar', [$class, 'bar']);

        $this->assertSame(['foo' => [$class, 'foo'], 'bar' => [$class, 'bar']], Feature::all());
        $this->assertSame(['foo' => [[$class, 'foo']], 'bar' => [[$class, 'bar']]], Feature::loadAll());
    }

    public function testFeatureClassDependenciesDoNotGoStale(): void
    {
        $createContainer = function () {
            $container = new Container;
            $container->singleton(FeatureDependency::class);
            $container->instance('events', new class {
                public function dispatch(): void
                {
                }
            });

            return $container;
        };
        $first = $createContainer();
        $firstDependency = null;
        $second = $createContainer();
        $secondDependency = null;

        Feature::define(MyFeatureWithDependency::class);

        Feature::store()->setContainer($first);
        $first->resolving(MyFeatureWithDependency::class, function (MyFeatureWithDependency $feature) use (&$firstDependency) {
            $firstDependency = $feature->dependency;
        });

        Feature::active(MyFeatureWithDependency::class);
        Feature::flushCache();

        Feature::store()->setContainer($second);
        $second->resolving(MyFeatureWithDependency::class, function (MyFeatureWithDependency $feature) use (&$secondDependency) {
            $secondDependency = $feature->dependency;
        });

        Feature::active(MyFeatureWithDependency::class);

        $this->assertInstanceOf(FeatureDependency::class, $first->make(FeatureDependency::class));
        $this->assertInstanceOf(FeatureDependency::class, $second->make(FeatureDependency::class));
        $this->assertNotSame($first->make(FeatureDependency::class), $second->make(FeatureDependency::class));
        $this->assertSame($first->make(FeatureDependency::class), $firstDependency);
        $this->assertSame($second->make(FeatureDependency::class), $secondDependency);
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

    public function testItCachesByIdentifierForFeatureScopableObjects(): void
    {
        $factory = fn () => new class implements FeatureScopeable {
            public function toFeatureIdentifier(string $driver): mixed
            {
                return 'foo';
            }
        };

        Feature::for($object = $factory())->activate('foo');

        $this->assertTrue(Feature::for($object)->active('foo'));
        $this->assertTrue(Feature::for($factory())->active('foo'));
    }

    public function testItCachesByIdentificationForOtherObjects(): void
    {
        $factory = fn ($name) => new User(['id' => 123, 'name' => $name]);

        Feature::for($object = $factory('Tim'))->activate('foo');

        $this->assertTrue(Feature::for($object)->active('foo'));
        $this->assertTrue(Feature::for($factory('Taylor'))->active('foo'));
    }

    public function testCachingOfFeatures(): void
    {
        $factory = fn () => new User(['id' => 123]);
        $user1 = $factory();
        $user2 = $factory();

        Feature::for($user1)->activate('myflag', 2);
        $this->assertEquals(2, Feature::for($user1)->active('myflag'));

        Feature::for($user1)->activate('myflag', 3);
        $this->assertEquals(3, Feature::for($user1)->active('myflag'));

        Feature::for($user2)->activate('myflag', 4);

        $this->assertEquals(4, Feature::for($user1)->value('myflag'));
        $this->assertEquals(4, Feature::for($user2)->value('myflag'));
    }

    public function testItCanListStoredFeatures(): void
    {
        Feature::define('foo', fn () => true);
        Feature::define('bar', fn () => true);

        Feature::for('Tim')->active('bar');

        $this->assertSame(Feature::stored(), ['bar']);
    }

    public function testItCanResolveFeatureInstance(): void
    {
        Feature::define(MyFeature::class);
        $this->assertInstanceOf(MyFeature::class, Feature::instance('my-feature'));
        $this->assertInstanceOf(MyFeature::class, Feature::instance(MyFeature::class));

        Feature::define(MyUnnamedFeature::class);
        $this->assertInstanceOf(MyUnnamedFeature::class, Feature::instance(MyUnnamedFeature::class));

        Feature::define('closure-based-feature', $closure = fn () => true);
        $this->assertSame($closure, Feature::instance('closure-based-feature'));

        Feature::define('value-based-feature', '123');
        $feature = Feature::instance('value-based-feature');
        $this->assertInstanceOf(Closure::class, $feature);
        $this->assertSame('123', $feature());

        Feature::define('lottery-based-feature', Lottery::odds(1, 1)->winner(fn () => '345'));
        $feature = Feature::instance('lottery-based-feature');
        $this->assertInstanceOf(Lottery::class, $feature);
        $this->assertSame('345', $feature());

        Feature::define('class-name-value-feature', MyFeature::class);
        $feature = Feature::instance('class-name-value-feature');
        $this->assertInstanceOf(Closure::class, $feature);
        $this->assertSame(MyFeature::class, $feature());

        Feature::define('null-value-feature', null);
        $feature = Feature::instance('null-value-feature');
        $this->assertInstanceOf(Closure::class, $feature);
        $this->assertNull($feature());
    }

    public function testRedefiningAFeatureClassUsesItsCurrentName(): void
    {
        $this->app->instance(MyFeature::class, $instance = new MyFeature);

        Feature::define(MyFeature::class);
        $this->assertSame('my-feature', Feature::name(MyFeature::class));

        $instance->name = 'renamed-feature';
        Feature::define(MyFeature::class);

        $this->assertSame('renamed-feature', Feature::name(MyFeature::class));
    }

    #[DataProvider('forgetStoreMethods')]
    public function testARebuiltStoreDoesntReuseTheForgottenStoresValues(Closure $forget): void
    {
        Feature::define('foo', fn () => 'first');
        $this->assertSame('first', Feature::value('foo'));

        $forget();
        Feature::define('foo', fn () => 'second');

        $this->assertSame('second', Feature::value('foo'));
    }

    public static function forgetStoreMethods(): array
    {
        return [
            'forgetDriver' => [fn () => Feature::forgetDriver('array')],
            'forgetDrivers' => [fn () => Feature::forgetDrivers()],
        ];
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

    public function testItHandlesIntegerScopesCorrectly(): void
    {
        Feature::define(IntScopeFeature::class);

        $this->assertSame([
            IntScopeFeature::class => true,
        ], Feature::for(1)->all());

        $this->assertSame([
            IntScopeFeature::class => false,
        ], Feature::for(10)->all());
    }

    public function testItCanHandlesDoubleScopesCorrectly(): void
    {
        Feature::define(DoubleScopeFeature::class);

        $this->assertSame([
            DoubleScopeFeature::class => true,
        ], Feature::for(1.1)->all());

        $this->assertSame([
            DoubleScopeFeature::class => true,
        ], Feature::for(1.10)->all());

        $this->assertSame([
            DoubleScopeFeature::class => false,
        ], Feature::for(1.11)->all());

        $this->assertSame([
            DoubleScopeFeature::class => false,
        ], Feature::for(10.00)->all());
    }

    public function testItCanHandlesFloatScopesCorrectly(): void
    {
        Feature::define(FloatScopeFeature::class);

        $this->assertSame([
            FloatScopeFeature::class => true,
        ], Feature::for(1.1)->all());

        $this->assertSame([
            FloatScopeFeature::class => true,
        ], Feature::for(1.10)->all());

        $this->assertSame([
            FloatScopeFeature::class => false,
        ], Feature::for(1.11)->all());

        $this->assertSame([
            FloatScopeFeature::class => false,
        ], Feature::for(10.00)->all());
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
}

class MyFeature
{
    public string $name = 'my-feature';

    public function __invoke($scope): string
    {
        return "{$scope}-123";
    }
}

class MyUnnamedFeature
{
    public function __invoke($scope): string
    {
        return "{$scope}-123";
    }
}

class MyFeatureWithResolveMethod
{
    public function resolve($scope): string
    {
        return "{$scope}-resolve-123";
    }
}

class MyFeatureWithUserScope
{
    public function resolve(User $user): bool
    {
        return true;
    }
}

class MyFeatureWithNoScope
{
    public function resolve(): bool
    {
        return true;
    }
}

class MyFeatureWithUntypedScope
{
    public function resolve($scope): bool
    {
        return true;
    }
}

class MyFeatureWithMixedScope
{
    public function resolve(mixed $scope): bool
    {
        return true;
    }
}

class MyFeatureWithNullableScope
{
    public function resolve(?User $scope): bool
    {
        return true;
    }
}

class MyFeatureWithDependency
{
    public function __construct(public FeatureDependency $dependency)
    {
    }

    public function resolve(): bool
    {
        return true;
    }
}

class FeatureDependency
{
}

class IntScopeFeature
{
    public function resolve(int $scope): bool
    {
        return in_array($scope, [1, 2, 3], true);
    }
}

class DoubleScopeFeature
{
    public function resolve(float $scope): bool
    {
        return in_array($scope, [1.10, 2.20, 3.30], true);
    }
}

class FloatScopeFeature
{
    public function resolve(float $scope): bool
    {
        return in_array($scope, [1.1, 2.2, 3.3], true);
    }
}

#[Name('my-feature-with-name-attribute')]
class MyFeatureWithNameAttribute
{
    public function __invoke($scope): string
    {
        return "{$scope}-attribute";
    }
}

#[Name('attribute-takes-priority')]
class MyFeatureWithBothNameAttributeAndProperty
{
    public string $name = 'property-name';

    public function __invoke($scope): string
    {
        return "{$scope}-both";
    }
}
