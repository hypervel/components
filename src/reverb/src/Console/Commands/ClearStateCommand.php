<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Console\Commands;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Redis\Factory as RedisFactory;
use Hypervel\Redis\RedisConnection;
use Hypervel\Reverb\Servers\Hypervel\Scaling\RedisSharedState;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'reverb:clear-state')]
class ClearStateCommand extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'reverb:clear-state
        {--dry-run : Count matching Reverb shared-state keys without deleting them}
        {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     */
    protected string $description = 'Clear Redis-backed Reverb shared state';

    /**
     * Execute the console command.
     */
    public function handle(ConfigRepository $config, RedisFactory $redis): int
    {
        $connectionName = $config->string('reverb.servers.reverb.scaling.connection');
        $connection = $redis->connection($connectionName);

        if ($this->option('dry-run') === true) {
            $count = $connection->withConnection(
                fn (RedisConnection $rawConnection): int => iterator_count(
                    $rawConnection->safeScan(RedisSharedState::KEY_PATTERN),
                ),
                transform: false,
            );

            $this->components->info("Found [{$count}] Reverb shared-state keys.");

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed(
            'All Reverb nodes sharing this Redis connection, database, and prefix must be stopped before clearing state.',
            true,
        )) {
            return self::FAILURE;
        }

        $count = $connection->flushByPattern(RedisSharedState::KEY_PATTERN);

        $this->components->info("Cleared [{$count}] Reverb shared-state keys.");

        return self::SUCCESS;
    }
}
