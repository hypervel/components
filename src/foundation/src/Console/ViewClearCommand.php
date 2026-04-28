<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\View\Engines\CompilerEngine;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

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
    public function handle()
    {
        $path = $this->hypervel['config']['view.compiled'];

        if (! $path) {
            throw new RuntimeException('View path not found.');
        }

        CompilerEngine::forgetCompiledOrNotExpired();

        foreach ($this->files->glob("{$path}/*") as $view) {
            if ($this->files->isDirectory($view)) {
                $this->files->deleteDirectory($view);
            } else {
                $this->files->delete($view);
            }
        }

        $this->components->info('Compiled views cleared successfully.');
    }
}
