<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use RuntimeException;

use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\is_symlink;
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
        $failures = [];

        (new LazyCollection($directories))
            ->each(function (string $directory) use (&$failures): void {
                $location = transform_realpath_to_relative($directory, $this->workingPath);

                if (! $this->filesystem->isDirectory($directory) && ! is_symlink($directory)) {
                    $this->components?->twoColumnDetail(
                        sprintf('Directory [%s] doesn\'t exist', $location),
                        '<fg=yellow;options=bold>SKIPPED</>',
                    );

                    return;
                }

                if ($this->confirmation === true && confirm(sprintf('Delete [%s] directory?', $location)) === false) {
                    return;
                }

                if (! $this->filesystem->deleteDirectory($directory)) {
                    $failures[] = $location;

                    return;
                }

                $this->components?->task(sprintf('Directory [%s] has been deleted', $location));
            });

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Unable to delete directories [%s].',
                implode(', ', $failures),
            ));
        }
    }
}
