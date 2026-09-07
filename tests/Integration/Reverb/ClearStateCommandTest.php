<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Reverb;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\ReverbServiceProvider;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Support\Facades\Redis;
use Hypervel\Testbench\TestCase;

class ClearStateCommandTest extends TestCase
{
    use InteractsWithRedis;

    /**
     * Get package providers.
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            ReverbServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('reverb.servers.reverb.scaling.connection', 'default');
    }

    public function testDryRunAndClearAffectOnlyRedisSharedState(): void
    {
        if ($this->usingRedisCluster()) {
            $this->markTestSkipped('Reverb scaling requires a standalone or Sentinel Redis connection.');
        }

        $redis = Redis::connection('default');
        $this->seedSharedState($redis);

        $survivors = [
            'reverb:webhook:{app}:buffer' => 'buffer',
            'reverb:webhook:{app}:flush' => 'flush',
            'reverb:webhook:{app}:processing' => 'processing',
            'reverb:message:123' => 'message',
            'unrelated:key' => 'unrelated',
        ];

        foreach ($survivors as $key => $value) {
            $redis->set($key, $value);
        }

        $this->assertSame(7, $this->sharedStateKeyCount($redis));

        $this->artisan('reverb:clear-state', ['--dry-run' => true])
            ->expectsOutputToContain('Found [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);

        $this->assertSame(7, $this->sharedStateKeyCount($redis));

        foreach ($survivors as $key => $value) {
            $this->assertSame($value, $redis->get($key));
        }

        $this->artisan('reverb:clear-state', ['--force' => true])
            ->expectsOutputToContain('Cleared [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);

        $this->assertSame(0, $this->sharedStateKeyCount($redis));

        foreach ($survivors as $key => $value) {
            $this->assertSame($value, $redis->get($key));
        }
    }

    private function seedSharedState(RedisProxy $redis): void
    {
        $state = new RedisSharedState($redis);

        $this->assertTrue($state->acquireConnectionSlot('app', 100));
        $state->subscribe('app', 'presence-channel');
        $state->subscribe('app', 'presence-channel', 'user');
        $this->assertTrue($state->trySubscriptionCountLock('app', 'presence-channel', 60_000));
        $this->assertTrue($state->tryCacheMissLock('app', 'presence-channel', 60_000));
        $state->setSmoothingPending('app', 'presence-channel', 60_000);
        $state->setMemberSmoothingPending('app', 'presence-channel', 'user', 60_000);
    }

    private function sharedStateKeyCount(RedisProxy $redis): int
    {
        return $redis->withConnection(
            fn (RedisConnection $connection): int => iterator_count(
                $connection->safeScan(RedisSharedState::KEY_PATTERN),
            ),
            transform: false,
        );
    }
}
