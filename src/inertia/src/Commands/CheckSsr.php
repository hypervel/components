<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Commands;

use Hypervel\Console\Command;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\HasHealthCheck;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'inertia:check-ssr')]
class CheckSsr extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'inertia:check-ssr';

    /**
     * The console command description.
     */
    protected string $description = 'Check the Inertia SSR server health status';

    /**
     * Check the Inertia SSR server health status.
     */
    public function handle(Gateway $gateway): int
    {
        if (! $gateway instanceof HasHealthCheck) {
            $this->error('The SSR gateway does not support health checks.');

            return self::FAILURE;
        }

        ($check = $gateway->isHealthy())
            ? $this->info('Inertia SSR server is running.')
            : $this->error('Inertia SSR server is not running.');

        return $check ? self::SUCCESS : self::FAILURE;
    }
}
