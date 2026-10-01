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
class DeleteDirectories extends Action
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
        $failures = [];

        (new LazyCollection($directories))
            ->each(function (string $directory) use (&$failures): void {
                $location = transform_realpath_to_relative($directory, $this->workingPath);

                Task::action(fn (): bool => $this->filesystem->deleteDirectory($directory))
                    ->response(function (bool $deleted) use (&$failures, $location): void {
                        if (! $deleted) {
                            $failures[] = $location;

                            return;
                        }

                        $this->components?->task(sprintf('Directory [%s] has been deleted', $location));
                    })->requirements(function () use ($directory, $location): bool {
                        if (! $this->filesystem->isDirectory($directory) && ! is_symlink($directory)) {
                            $this->components?->twoColumnDetail(
                                sprintf('Directory [%s] doesn\'t exist', $location),
                                '<fg=yellow;options=bold>SKIPPED</>',
                            );

                            return false;
                        }

                        if ($this->confirmation === true && confirm(sprintf('Delete [%s] directory?', $location)) === false) {
                            return false;
                        }

                        return true;
                    })->dispatch($this->pretending);
            });

        if ($failures !== []) {
            throw new RuntimeException(sprintf(
                'Unable to delete directories [%s].',
                implode(', ', $failures),
            ));
        }
    }
}
