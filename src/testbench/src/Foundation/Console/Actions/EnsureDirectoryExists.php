<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

class EnsureDirectoryExists
{
    /**
     * Construct a new action instance.
     */
    public function __construct(
        public readonly Filesystem $filesystem,
        public readonly ?ComponentsFactory $components = null,
        public ?string $workingPath = null,
        public readonly bool $confirmation = false,
    ) {
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

                if ($this->filesystem->isDirectory($directory)) {
                    $this->components?->twoColumnDetail(
                        sprintf('Directory [%s] already exists', $location),
                        '<fg=yellow;options=bold>SKIPPED</>',
                    );

                    return;
                }

                if ($this->confirmation === true && confirm(sprintf('Ensure [%s] directory exists?', $location)) === false) {
                    return;
                }

                $this->filesystem->ensureDirectoryExists($directory, 0755, true);
                $this->filesystem->copy(join_paths(__DIR__, 'stubs', '.gitkeep'), join_paths($directory, '.gitkeep'));

                $this->components?->task(sprintf('Prepare [%s] directory', $location));
            });
    }
}
