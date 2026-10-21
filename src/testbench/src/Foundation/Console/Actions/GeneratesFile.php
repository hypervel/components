<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\Console\Task;
use RuntimeException;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

/**
 * @api
 */
class GeneratesFile extends Action
{
    /**
     * Construct a new action instance.
     */
    public function __construct(
        public readonly Filesystem $filesystem,
        public readonly ?ComponentsFactory $components = null,
        public readonly bool $force = false,
        public ?string $workingPath = null,
        public readonly bool $confirmation = false,
        bool $pretending = false,
    ) {
        $this->pretending = $pretending;
    }

    /**
     * Handle the action.
     */
    public function handle(string|false|null $from, string|false|null $to): void
    {
        if (! is_string($from) || ! is_string($to)) {
            return;
        }

        $location = transform_realpath_to_relative($to, $this->workingPath);

        Task::action(function () use ($from, $to): bool {
            if (! $this->filesystem->copy($from, $to)) {
                throw new RuntimeException("Unable to generate file [{$to}].");
            }

            $gitKeepFile = join_paths(dirname($to), '.gitkeep');

            if ($this->filesystem->exists($gitKeepFile)) {
                $this->filesystem->delete($gitKeepFile);
            }

            return true;
        })->response(function (bool $generated, bool $pretending) use ($location): void {
            $this->components?->task(sprintf($pretending ? 'File [%s] would be generated' : 'File [%s] generated', $location));
        })->requirements(function () use ($from, $to, $location): bool {
            if (! $this->filesystem->exists($from)) {
                $this->components?->twoColumnDetail(
                    sprintf('Source file [%s] doesn\'t exist', transform_realpath_to_relative($from, $this->workingPath)),
                    '<fg=yellow;options=bold>SKIPPED</>',
                );

                return false;
            }

            if (! $this->force && $this->filesystem->exists($to)) {
                $this->components?->twoColumnDetail(
                    sprintf('File [%s] already exists', $location),
                    '<fg=yellow;options=bold>SKIPPED</>',
                );

                return false;
            }

            if ($this->confirmation === true && confirm(sprintf('Generate [%s] file?', $location)) === false) {
                return false;
            }

            return true;
        })->dispatch($this->pretending);
    }
}
