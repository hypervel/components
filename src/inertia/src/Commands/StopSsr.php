<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Commands;

use GuzzleHttp\Exception\TransferException;
use Hypervel\Console\Command;
use Hypervel\Inertia\Ssr\HttpGateway;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'inertia:stop-ssr')]
class StopSsr extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'inertia:stop-ssr';

    /**
     * The console command description.
     */
    protected string $description = 'Stop the Inertia SSR server';

    /**
     * Stop the Inertia SSR server.
     */
    public function handle(HttpGateway $gateway): int
    {
        if (! $gateway->isHealthy()) {
            $this->error('Unable to connect to Inertia SSR server.');

            return self::FAILURE;
        }

        try {
            if (! $gateway->shutdown()) {
                $this->error('Inertia SSR server refused to stop.');

                return self::FAILURE;
            }
        } catch (TransferException) {
            // The official shutdown endpoint terminates after a verified health
            // response and may close the connection without sending a response.
        }

        $this->info('Inertia SSR server stopped.');

        return self::SUCCESS;
    }
}
