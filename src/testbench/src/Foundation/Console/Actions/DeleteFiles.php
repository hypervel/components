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
        $failures = [];

        (new LazyCollection($files))
            ->reject(static fn (string $file) => str_ends_with($file, '.gitkeep') || str_ends_with($file, '.gitignore'))
            ->each(function (string $file) use (&$failures): void {
                $location = transform_realpath_to_relative($file, $this->workingPath);

                if (! $this->filesystem->isFile($file) && ! is_symlink($file)) {
                    $this->components?->twoColumnDetail(
                        $this->filesystem->isDirectory($file)
                            ? sprintf('[%s] is a directory', $location)
                            : sprintf('File [%s] doesn\'t exist', $location),
                        '<fg=yellow;options=bold>SKIPPED</>',
                    );

                    return;
                }

                if ($this->confirmation === true && confirm(sprintf('Delete [%s] file?', $location)) === false) {
                    return;
                }

                if (! $this->filesystem->delete($file)) {
                    $failures[] = $location;

                    return;
                }

                $this->components?->task(sprintf('File [%s] has been deleted', $location));
            });

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Unable to delete files [%s].',
                implode(', ', $failures),
            ));
        }
    }
}
