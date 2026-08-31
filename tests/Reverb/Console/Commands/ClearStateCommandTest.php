<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Console\Commands;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Tests\Reverb\ReverbTestCase;
use Mockery as m;

class ClearStateCommandTest extends ReverbTestCase
{
    public function testDryRunCountsKeysWithoutConfirmationOrDeletion(): void
    {
        $rawConnection = m::mock(RedisConnection::class);
        $rawConnection->expects('safeScan')
            ->with(RedisSharedState::KEY_PATTERN)
            ->andReturn((static function () {
                yield 'first';
                yield 'second';
            })());

        $connection = m::mock(RedisProxy::class);
        $connection->expects('withConnection')
            ->withArgs(fn (callable $callback, bool $transform): bool => $transform === false)
            ->andReturnUsing(
                fn (callable $callback, bool $transform): int => $callback($rawConnection),
            );
        $connection->shouldNotReceive('flushByPattern');
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state', ['--dry-run' => true])
            ->expectsOutputToContain('Found [2] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    public function testDestructiveRunCanBeCancelledInAnyEnvironment(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->shouldNotReceive('flushByPattern');
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    public function testConfirmedDestructiveRunClearsSharedState(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->expects('flushByPattern')
            ->with(RedisSharedState::KEY_PATTERN)
            ->andReturn(7);
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state')
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->expectsOutputToContain('Cleared [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    public function testForceSkipsConfirmation(): void
    {
        $connection = m::mock(RedisProxy::class);
        $connection->expects('flushByPattern')
            ->with(RedisSharedState::KEY_PATTERN)
            ->andReturn(7);
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state', ['--force' => true])
            ->expectsOutputToContain('Cleared [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    private function bindRedisConnection(RedisProxy $connection): void
    {
        config()->set('reverb.servers.reverb.scaling.connection', 'queue');

        $redis = m::mock(RedisFactory::class);
        $redis->expects('connection')->with('queue')->andReturn($connection);

        $this->app->instance('redis', $redis);
    }
}
