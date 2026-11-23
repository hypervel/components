<?php

declare(strict_types=1);

namespace Hypervel\Tests\Reverb\Console\Commands;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Redis\RedisProxy;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Hypervel\Tests\Reverb\ReverbTestCase;
use JMac\Testing\Double;
use JMac\Testing\Matching\Argument;

class ClearStateCommandTest extends ReverbTestCase
{
    public function testDryRunCountsKeysWithoutConfirmationOrDeletion(): void
    {
        $rawConnection = Double::for(RedisConnection::class)->strict();
        $rawConnection->expects('safeScan')
            ->with(RedisSharedState::KEY_PATTERN)
            ->returns((static function () {
                yield 'first';
                yield 'second';
            })());

        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('withConnection')
            ->with(Argument::type('callable'), false)
            ->resolves(
                fn (callable $callback, bool $transform): int => $callback($rawConnection),
            );
        $connection->expects('flushByPattern')->never();
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state', ['--dry-run' => true])
            ->expectsOutputToContain('Found [2] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    public function testDestructiveRunCanBeCancelledInAnyEnvironment(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('flushByPattern')->never();
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->expectsOutputToContain('Command cancelled.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    public function testConfirmedDestructiveRunClearsSharedState(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('flushByPattern')
            ->with(RedisSharedState::KEY_PATTERN)
            ->returns(7);
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state')
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->expectsOutputToContain('Cleared [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    public function testForceSkipsConfirmation(): void
    {
        $connection = Double::for(RedisProxy::class)->strict();
        $connection->expects('flushByPattern')
            ->with(RedisSharedState::KEY_PATTERN)
            ->returns(7);
        $this->bindRedisConnection($connection);

        $this->artisan('reverb:clear-state', ['--force' => true])
            ->expectsOutputToContain('Cleared [7] Reverb shared-state keys.')
            ->assertExitCode(HypervelCommand::SUCCESS);
    }

    /**
     * Bind the configured Redis connection for the command.
     */
    private function bindRedisConnection(RedisProxy $connection): void
    {
        config()->set('reverb.servers.reverb.scaling.connection', 'queue');

        $redis = Double::for(RedisFactory::class)->strict();
        $redis->expects('connection')->with('queue')->returns($connection);

        $this->app->instance('redis', $redis);
    }
}
