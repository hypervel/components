<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Engines\CompilerEngine;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'view:clear')]
class ViewClearCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'view:clear';

    /**
     * The console command description.
     */
    protected string $description = 'Clear all compiled view files';

    /**
     * Create a new view clear command instance.
     */
    public function __construct(
        protected Filesystem $files,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @throws RuntimeException
     */
    public function handle(): void
    {
        $path = $this->hypervel->make('config')->string('view.compiled');

        if (! $path) {
            throw new RuntimeException('View path not found.');
        }

        CompilerEngine::forgetCompiledOrNotExpired();

        $views = $this->files->glob("{$path}/*");

        if ($views === false) {
            throw new RuntimeException("Unable to enumerate compiled views in [{$path}].");
        }

        $exception = null;

        foreach ($views as $view) {
            try {
                $deleted = $this->files->isDirectory($view)
                    ? $this->files->deleteDirectory($view)
                    : $this->files->delete($view);

                if (! $deleted && $this->files->exists($view)) {
                    throw new RuntimeException("Unable to delete the compiled view [{$view}].");
                }
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        $this->components->info('Compiled views cleared successfully.');
    }
}
