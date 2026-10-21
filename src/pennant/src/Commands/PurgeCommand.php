<?php

declare(strict_types=1);

namespace Hypervel\Pennant\Commands;

use Hypervel\Console\Command;
use Hypervel\Pennant\FeatureManager;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'pennant:purge', aliases: ['pennant:clear'])]
class PurgeCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $signature = 'pennant:purge
                            {features?* : The features to purge}
                            {--except=* : The features that should be excluded from purging}
                            {--except-registered : Purge all features except those registered}
                            {--store= : The store to purge the features from}';

    /**
     * The console command description.
     */
    protected string $description = 'Delete Pennant features from storage';

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['pennant:clear'];

    /**
     * Execute the console command.
     */
    public function handle(FeatureManager $manager): int
    {
        $store = $manager->store($this->option('store'));

        $features = $this->argument('features') ?: null;

        $except = collect($this->option('except'))
            ->when($this->option('except-registered'), fn ($except) => $except->merge($store->defined()))
            ->all();

        if ($except) {
            // Compare registered names, so a feature class matches the name it's stored under.
            // Names must match exactly, so "01" and "1" stay distinct.
            $features = array_values(array_diff(
                array_map($store->name(...), $features ?: $store->stored()),
                array_map($store->name(...), $except),
            ));
        }

        $store->purge($features);

        if ($features) {
            $this->components->info(implode(', ', $features) . ' successfully purged from storage.');
        } elseif ($except) {
            $this->components->info('No features to purge from storage.');
        } else {
            $this->components->info('All features successfully purged from storage.');
        }

        return self::SUCCESS;
    }
}
