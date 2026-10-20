<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant\Feature;

use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Pennant\Drivers\ArrayDriver;
use Hypervel\Pennant\Events\FeaturesPurged;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Pennant\TestCase;
use InvalidArgumentException;

class PurgeCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function testItCanPurgeFlags(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('bar');
        Feature::for('taylor')->active('foo');

        $this->assertSame(3, DB::table('features')->count());

        $this->artisan('pennant:purge foo')->expectsOutputToContain('foo successfully purged from storage.');

        $this->assertSame(1, DB::table('features')->count());

        $this->artisan('pennant:purge bar');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanPurgeMultipleFeatures(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', true);
        Feature::define('baz', true);

        Feature::for('tim')->active('foo');
        Feature::for('tim')->active('bar');
        Feature::for('taylor')->active('bar');
        Feature::for('taylor')->active('baz');

        $this->assertSame(4, DB::table('features')->count());

        $this->artisan('pennant:purge foo bar')->expectsOutputToContain('foo, bar successfully purged from storage.');

        $this->assertSame(1, DB::table('features')->count());

        $this->artisan('pennant:purge baz');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanPurgeAllFeatureFlags(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');
        Feature::for('taylor')->active('bar');

        $this->assertSame(3, DB::table('features')->count());

        $this->artisan('pennant:purge')->expectsOutputToContain('All features successfully purged from storage.');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testItCanSpecifyADriver(): void
    {
        config(['pennant.stores.custom' => ['driver' => 'custom']]);

        Feature::extend('custom', fn () => new class('custom', null, []) extends ArrayDriver {
            public function purge(?array $features): void
            {
            }
        });

        Feature::store('database')->define('foo', true);
        Feature::store('database')->define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');
        Feature::for('taylor')->active('bar');

        $this->assertSame(3, DB::table('features')->count());

        $this->artisan('pennant:purge --store=custom');

        $this->assertSame(3, DB::table('features')->count());

        $this->artisan('pennant:purge --store=database');

        $this->assertSame(0, DB::table('features')->count());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pennant store [foo] is not defined.');
        $this->artisan('pennant:purge --store=foo');
    }

    public function testItCanExcludeFeaturesToPurgeFromStorage(): void
    {
        Feature::define('foo', true);
        Feature::define('bar', false);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');

        Feature::for('taylor')->active('bar');

        DB::table('features')->insert([
            'name' => 'baz',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->assertCount(3, DB::table('features')->get()->unique('name'));

        $this->artisan('pennant:purge --except=foo')->expectsOutputToContain('bar, baz successfully purged from storage.');

        $this->assertCount(1, DB::table('features')->get()->unique('name'));

        $this->artisan('pennant:purge foo')->expectsOutputToContain('foo successfully purged from storage.');

        $this->assertSame(0, DB::table('features')->count());
    }

    public function testExcludedFeatureNamesMatchExactly(): void
    {
        Feature::activate(['01', '1', '2']);
        Event::fake([FeaturesPurged::class]);

        $this->artisan('pennant:purge --except=01 --except=1')->expectsOutputToContain('2 successfully purged from storage.');

        $this->assertSame(['01', '1'], DB::table('features')->orderBy('name')->pluck('name')->all());
        Event::assertDispatched(FeaturesPurged::class, fn (FeaturesPurged $event) => $event->features === ['2']);
    }

    public function testItOutputsThatNoFeaturesHaveBeenPurged(): void
    {
        Feature::define('foo', true);

        Feature::for('tim')->active('foo');
        Feature::for('taylor')->active('foo');

        $this->assertSame(2, DB::table('features')->where('name', 'foo')->count());

        $this->artisan('pennant:purge --except=foo')->expectsOutputToContain('No features to purge from storage.');

        $this->assertSame(2, DB::table('features')->where('name', 'foo')->count());
    }

    public function testItCanCombineExceptAndFeaturesAsArguments(): void
    {
        DB::table('features')->insert([
            'name' => 'foo',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        DB::table('features')->insert([
            'name' => 'bar',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        DB::table('features')->insert([
            'name' => 'baz',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->artisan('pennant:purge foo bar --except=bar')->expectsOutputToContain('foo successfully purged from storage.');

        $this->assertSame(['bar', 'baz'], DB::table('features')->pluck('name')->all());
    }

    public function testItCanPurgeFeaturesExceptThoseRegistered(): void
    {
        Feature::define('foo', fn () => true);
        DB::table('features')->insert([
            'name' => 'foo',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        DB::table('features')->insert([
            'name' => 'bar',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
        DB::table('features')->insert([
            'name' => 'baz',
            'scope' => 'Tim',
            'value' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        $this->artisan('pennant:purge --except-registered')->expectsOutputToContain('bar, baz successfully purged from storage.');

        $this->assertSame(['foo'], DB::table('features')->pluck('name')->all());
    }
}
