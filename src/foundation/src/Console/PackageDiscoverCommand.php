<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'package:discover')]
class PackageDiscoverCommand extends Command
{
    /**
     * The console command signature.
     */
    protected ?string $signature = 'package:discover';

    /**
     * The console command description.
     */
    protected string $description = 'Rebuild the cached package manifest';

    /**
     * Execute the console command.
     */
    public function handle(PackageManifest $manifest): void
    {
        $this->components->info('Discovering packages');

        $manifest->build();

        (new Collection($manifest->manifest))
            ->keys()
            ->each(fn ($description) => $this->components->task($description))
            ->whenNotEmpty(fn () => $this->newLine());
    }
}
