<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Commands;

use Hypervel\Console\Command;
use Hypervel\Http\Client\ConnectionException;
use Hypervel\Inertia\Ssr\HttpGateway;
use Hypervel\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'inertia:stop-ssr')]
class StopSsr extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'inertia:stop-ssr';

    /**
     * The console command description.
     */
    protected string $description = 'Stop the Inertia SSR server';

    /**
     * Stop the Inertia SSR server.
     */
    public function handle(HttpGateway $gateway): int
    {
        $url = $gateway->getProductionUrl('/shutdown');

        try {
            Http::timeout(3)->get($url);
        } catch (ConnectionException $e) {
            // The shutdown endpoint closes the connection without a response,
            // which triggers an "Empty reply from server" error. This is expected.
            // Real connection failures produce "Connection refused" or similar.
            if (! str_contains($e->getMessage(), 'Empty reply from server')) {
                $this->error('Unable to connect to Inertia SSR server.');

                return self::FAILURE;
            }
        }

        $this->info('Inertia SSR server stopped.');

        return self::SUCCESS;
    }
}
