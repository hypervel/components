<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;

use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

class DeleteDirectories
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

                if (! $this->filesystem->isDirectory($directory)) {
                    $this->components?->twoColumnDetail(
                        sprintf('Directory [%s] doesn\'t exists', $location),
                        '<fg=yellow;options=bold>SKIPPED</>',
                    );

                    return;
                }

                if ($this->confirmation === true && confirm(sprintf('Delete [%s] directory?', $location)) === false) {
                    return;
                }

                $this->filesystem->deleteDirectory($directory);

                $this->components?->task(sprintf('Directory [%s] has been deleted', $location));
            });
    }
}
