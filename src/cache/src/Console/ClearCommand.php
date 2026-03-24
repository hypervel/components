<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use BadMethodCallException;
use Hypervel\Cache\CacheManager;
use Hypervel\Console\Command;
use Hypervel\Contracts\Cache\Repository;
use Hypervel\Filesystem\Filesystem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'cache:clear')]
class ClearCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'cache:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Flush the application cache';

    /**
     * Create a new cache clear command instance.
     */
    public function __construct(
        protected CacheManager $cache,
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('locks')) {
            return $this->clearLocks();
        }

        $this->hypervel['events']->dispatch(
            'cache:clearing',
            [$this->argument('store'), $this->tags()]
        );

        /** @phpstan-ignore method.notFound (flush() is on TaggedCache or via __call to the store) */
        $successful = $this->cache()->flush();

        $this->flushRuntime();

        if (! $successful) {
            $this->components->error('Failed to clear cache. Make sure you have the appropriate permissions.');

            return self::FAILURE;
        }

        $this->hypervel['events']->dispatch(
            'cache:cleared',
            [$this->argument('store'), $this->tags()]
        );

        $this->components->info('Application cache cleared successfully.');

        return self::SUCCESS;
    }

    /**
     * Clear all locks from the cache store.
     */
    protected function clearLocks(): int
    {
        if (! empty($this->tags())) {
            $this->components->error('Cache tags cannot be used when clearing locks.');

            return self::FAILURE;
        }

        try {
            /** @phpstan-ignore method.notFound (flushLocks() is on the concrete Repository, not the contract) */
            $successful = $this->cache()->flushLocks();
        } catch (BadMethodCallException) {
            $this->components->error('This cache store does not support clearing locks.');

            return self::FAILURE;
        }

        if (! $successful) {
            $this->components->error('Failed to clear cache locks. Make sure you have the appropriate permissions.');

            return self::FAILURE;
        }

        $this->components->info('Application cache locks cleared successfully.');

        return self::SUCCESS;
    }

    /**
     * Get the cache instance for the command.
     */
    protected function cache(): Repository
    {
        $cache = $this->cache->store($this->argument('store'));

        /* @phpstan-ignore method.notFound (tags() is on TaggableStore, not the contract) */
        return empty($this->tags()) ? $cache : $cache->tags($this->tags());
    }

    /**
     * Flush the runtime cache directory.
     */
    public function flushRuntime(): void
    {
        $this->files->deleteDirectory(base_path('runtime/container'));
    }

    /**
     * Get the tags passed to the command.
     */
    protected function tags(): array
    {
        return array_filter(explode(',', $this->option('tags') ?? ''));
    }

    /**
     * Get the console command arguments.
     */
    protected function getArguments(): array
    {
        return [
            ['store', InputArgument::OPTIONAL, 'The name of the store you would like to clear'],
        ];
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['tags', null, InputOption::VALUE_OPTIONAL, 'The cache tags you would like to clear', null],
            ['locks', null, InputOption::VALUE_NONE, 'Only clear cache locks'],
        ];
    }
}
