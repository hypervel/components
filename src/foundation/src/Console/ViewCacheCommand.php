<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

#[AsCommand(name: 'view:cache')]
class ViewCacheCommand extends Command
{
    protected ?string $signature = 'view:cache';

    protected string $description = "Compile all of the application's Blade templates";

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->callSilent('view:clear');

        $this->paths()->each(function ($path) {
            $prefix = $this->output->isVeryVerbose() ? '<fg=yellow;options=bold>DIR</> ' : '';

            $this->components->task($prefix . $path, null, OutputInterface::VERBOSITY_VERBOSE);

            $this->compileViews($this->bladeFilesIn([$path]));
        });

        $this->newLine();

        $this->components->info('Blade templates cached successfully.');
    }

    /**
     * Compile the given view files.
     */
    protected function compileViews(Collection $views): void
    {
        $compiler = $this->hypervel['view']->getEngineResolver()->resolve('blade')->getCompiler();

        $views->map(function (SplFileInfo $file) use ($compiler) {
            $this->components->task('    ' . $file->getRelativePathname(), null, OutputInterface::VERBOSITY_VERY_VERBOSE);

            $compiler->compile($file->getRealPath());
        });

        if ($this->output->isVeryVerbose()) {
            $this->newLine();
        }
    }

    /**
     * Get the Blade files in the given path.
     */
    protected function bladeFilesIn(array $paths): Collection
    {
        $extensions = (new Collection($this->hypervel['view']->getExtensions()))
            ->filter(fn ($value) => $value === 'blade')
            ->keys()
            ->map(fn ($extension) => "*.{$extension}")
            ->all();

        return new Collection(
            Finder::create()
                ->in($paths)
                ->exclude('vendor')
                ->name($extensions)
                ->files()
        );
    }

    /**
     * Get all of the possible view paths.
     */
    protected function paths(): Collection
    {
        $finder = $this->hypervel['view']->getFinder();

        return (new Collection($finder->getPaths()))->merge(
            (new Collection($finder->getHints()))->flatten()
        );
    }
}
