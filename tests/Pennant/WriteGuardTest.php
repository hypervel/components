<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pennant;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Pennant\Feature;
use Hypervel\Pennant\FeatureManager;
use Hypervel\Pennant\FeaturePartition;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class WriteGuardTest extends TestCase
{
    use RefreshDatabase;

    protected bool $blocked = false;

    protected int $resolutions = 0;

    protected int $writes = 0;

    protected RuntimeException $failure;

    /**
     * Use persistent feature storage.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('pennant.default', 'database');
    }

    /**
     * Register the guard before defining features.
     */
    protected function setUpInCoroutine(): void
    {
        $this->failure = new RuntimeException('Feature storage is read-only.');
        Feature::guardWritesUsing(function (string $store, ?FeaturePartition $partition): void {
            $this->assertSame('database', $store);
            $this->assertNull($partition);
            ++$this->writes;

            if ($this->blocked) {
                throw $this->failure;
            }
        });
        Feature::define('flag', function (): int {
            return ++$this->resolutions;
        });
    }

    #[DataProvider('mutations')]
    public function testGuardRejectsWritesBeforeResolvingValues(Closure $mutate): void
    {
        $this->blocked = true;

        try {
            $mutate();
            $this->fail('The write guard must reject the mutation.');
        } catch (RuntimeException $exception) {
            $this->assertSame($this->failure, $exception);
        }

        $this->assertSame(1, $this->writes);
        $this->assertSame(0, $this->resolutions);
        $this->assertDatabaseCount('features', 0);
    }

    /**
     * Provide the database mutation surfaces.
     */
    public static function mutations(): array
    {
        return [
            'first resolution' => [fn () => Feature::for('person')->value('flag')],
            'bulk first resolution' => [fn () => Feature::for(['one', 'two'])->load('flag')],
            'set' => [fn () => Feature::store()->set('flag', 'person', true)],
            'set all' => [fn () => Feature::for(['one', 'two'])->activate('flag')],
            'set for all scopes' => [fn () => Feature::activateForEveryone('flag')],
            'delete' => [fn () => Feature::for('person')->forget('flag')],
            'purge' => [fn () => Feature::purge('flag')],
            'purge all' => [fn () => Feature::purge()],
        ];
    }

    public function testReadsUnknownFeaturesAndEmptyMutationsDoNotInvokeTheGuard(): void
    {
        $this->assertSame(1, Feature::for('person')->value('flag'));
        $this->blocked = true;
        Feature::flushCache();

        $this->assertSame(1, Feature::for('person')->value('flag'));
        $this->assertFalse(Feature::for('person')->value('unknown'));
        Feature::purge([]);
        Feature::activate([]);
        Feature::store('array')->for('person')->activate('flag');
        Feature::store('array')->flushSharedCache();

        $this->assertTrue(Feature::store('array')->for('person')->active('flag'));
        $this->assertSame(1, $this->writes);
    }

    #[DataProvider('registrations')]
    public function testRegistrationMustBeOnceAndBeforeResolvingStores(string $method, bool $resolve): void
    {
        $manager = new FeatureManager($this->app);
        $register = $method === 'partition'
            ? fn () => $manager->resolvePartitionUsing('team_id', fn () => 1)
            : fn () => $manager->guardWritesUsing(static function (): void {});
        $name = $method === 'partition' ? 'partition resolver' : 'write guard';

        if ($resolve) {
            $manager->store('array');
        } else {
            $register();
        }

        $this->expectExceptionObject(new RuntimeException($resolve
            ? "Register the Pennant {$name} before resolving any Pennant store."
            : "The Pennant {$name} has already been registered."));

        $register();
    }

    /**
     * Provide conflicting boot registrations.
     */
    public static function registrations(): array
    {
        return [
            'duplicate partition' => ['partition', false],
            'duplicate guard' => ['guard', false],
            'late guard' => ['guard', true],
        ];
    }
}
