<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'grpc:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'grpc:install {--force : Overwrite existing configuration and routes}';

    /**
     * The console command description.
     */
    protected string $description = 'Install the Hypervel gRPC configuration and routes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $arguments = ['--provider' => 'Hypervel\Grpc\GrpcServiceProvider'];

        if ($this->option('force')) {
            $arguments['--force'] = true;
        }

        if ($this->callSilent('vendor:publish', [
            ...$arguments,
            '--tag' => 'grpc-config',
        ]) !== self::SUCCESS || $this->callSilent('vendor:publish', [
            ...$arguments,
            '--tag' => 'grpc-routes',
        ]) !== self::SUCCESS) {
            $this->components->error('Unable to install the gRPC resources.');

            return self::FAILURE;
        }

        $this->components->info('gRPC resources installed successfully.');
        $this->components->info('Set GRPC_SERVER_ENABLED=true to enable the gRPC listener.');

        return self::SUCCESS;
    }
}
