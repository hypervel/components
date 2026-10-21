<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Console\Task;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

/**
 * @api
 */
class EnsureDirectoryExists extends Action
{
    /**
     * Construct a new action instance.
     */
    public function __construct(
        public readonly Filesystem $filesystem,
        public readonly ?ComponentsFactory $components = null,
        public ?string $workingPath = null,
        public readonly bool $confirmation = false,
        bool $pretending = false,
    ) {
        $this->pretending = $pretending;
    }

    /**
     * Handle the action.
     *
     * @param iterable<int, string> $directories
     */
    public function handle(iterable $directories): void
    {
        (new LazyCollection($directories))
            ->each(function (string $directory): void {
                $location = transform_realpath_to_relative($directory, $this->workingPath);

                Task::action(function () use ($directory): bool {
                    $this->filesystem->ensureDirectoryExists($directory, 0755, true);
                    $placeholder = join_paths($directory, '.gitkeep');

                    if (! $this->filesystem->copy(join_paths(__DIR__, 'Fixtures', '.gitkeep'), $placeholder)) {
                        throw new RuntimeException("Unable to create placeholder file [{$placeholder}].");
                    }

                    return true;
                })->response(function () use ($location): void {
                    $this->components?->task(sprintf('Prepare [%s] directory', $location));
                })->requirements(function () use ($directory, $location): bool {
                    if ($this->filesystem->isDirectory($directory)) {
                        $this->components?->twoColumnDetail(
                            sprintf('Directory [%s] already exists', $location),
                            '<fg=yellow;options=bold>SKIPPED</>',
                        );

                        return false;
                    }

                    if ($this->confirmation === true && confirm(sprintf('Ensure [%s] directory exists?', $location)) === false) {
                        return false;
                    }

                    return true;
                })->dispatch($this->pretending);
            });
    }
}
