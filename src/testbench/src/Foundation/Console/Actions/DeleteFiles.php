<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;

use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

class DeleteFiles
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
     * @param iterable<int, string> $files
     */
    public function handle(iterable $files): void
    {
        (new LazyCollection($files))
            ->reject(static fn (string $file) => str_ends_with($file, '.gitkeep') || str_ends_with($file, '.gitignore'))
            ->each(function (string $file): void {
                $location = transform_realpath_to_relative($file, $this->workingPath);

                if (! $this->filesystem->exists($file)) {
                    $this->components?->twoColumnDetail(
                        sprintf('File [%s] doesn\'t exists', $location),
                        '<fg=yellow;options=bold>SKIPPED</>',
                    );

                    return;
                }

                if ($this->confirmation === true && confirm(sprintf('Delete [%s] file?', $location)) === false) {
                    return;
                }

                $this->filesystem->delete($file);

                $this->components?->task(sprintf('File [%s] has been deleted', $location));
            });
    }
}
