<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Console\Task;
use RuntimeException;

use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\transform_realpath_to_relative;

/**
 * @api
 */
class DeleteFiles extends Action
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
     * @param iterable<int, string> $files
     */
    public function handle(iterable $files): void
    {
        $failures = [];

        (new LazyCollection($files))
            ->reject(static fn (string $file) => str_ends_with($file, '.gitkeep') || str_ends_with($file, '.gitignore'))
            ->each(function (string $file) use (&$failures): void {
                $location = transform_realpath_to_relative($file, $this->workingPath);

                Task::action(fn (): bool => $this->filesystem->delete($file))
                    ->response(function (bool $deleted, bool $pretending) use (&$failures, $location): void {
                        if (! $deleted) {
                            $failures[] = $location;

                            return;
                        }

                        $this->components?->task(sprintf(
                            $pretending ? 'File [%s] would be deleted' : 'File [%s] has been deleted',
                            $location,
                        ));
                    })->requirements(function () use ($file, $location): bool {
                        if (! $this->filesystem->isFile($file) && ! is_symlink($file)) {
                            $this->components?->twoColumnDetail(
                                $this->filesystem->isDirectory($file)
                                    ? sprintf('[%s] is a directory', $location)
                                    : sprintf('File [%s] doesn\'t exist', $location),
                                '<fg=yellow;options=bold>SKIPPED</>',
                            );

                            return false;
                        }

                        if ($this->confirmation === true && confirm(sprintf('Delete [%s] file?', $location)) === false) {
                            return false;
                        }

                        return true;
                    })->dispatch($this->pretending);
            });

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Unable to delete files [%s].',
                implode(', ', $failures),
            ));
        }
    }
}
