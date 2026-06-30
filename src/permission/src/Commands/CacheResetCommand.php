<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Hypervel\Console\Command;
use Hypervel\Permission\PermissionRegistrar;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'permission:cache-reset')]
class CacheResetCommand extends Command
{
    protected ?string $signature = 'permission:cache-reset';

    protected string $description = 'Reset the permission cache';

    /**
     * Execute the console command.
     */
    public function handle(PermissionRegistrar $permissionRegistrar): int
    {
        $cacheExists = $permissionRegistrar->getCacheRepository()->has($permissionRegistrar->getCacheKey());

        if ($permissionRegistrar->forgetCachedPermissions()) {
            $this->info('Permission cache flushed.');
        } elseif ($cacheExists) {
            $this->error('Unable to flush cache.');
        }

        return self::SUCCESS;
    }
}
