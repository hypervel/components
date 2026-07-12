<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\TestbenchPackage;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'dogfood:probe')]
class DogfoodProbeCommand extends Command
{
    protected string $description = 'Probe the dogfood package runtime.';

    /**
     * Execute the console command.
     */
    public function handle(ConfigRepository $config): int
    {
        $this->line($config->boolean('dogfood.package_provider_loaded', false) ? 'package-provider' : 'missing-package-provider');
        $this->line($config->boolean('dogfood.workbench_provider_loaded', false) ? 'workbench-provider' : 'missing-workbench-provider');

        return self::SUCCESS;
    }
}
