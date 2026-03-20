<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console\Actions;

use Hypervel\Console\View\Components\Factory as ComponentsFactory;
use Hypervel\Filesystem\Filesystem;

use function Hypervel\Filesystem\join_paths;
use function Hypervel\Prompts\confirm;
use function Hypervel\Testbench\transform_realpath_to_relative;

class GeneratesFile
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
    ) {
    }

    /**
     * Handle the action.
     */
    public function handle(string|false|null $from, string|false|null $to): void
    {
        if (! is_string($from) || ! is_string($to)) {
            return;
        }

        if (! $this->filesystem->exists($from)) {
            $this->components?->twoColumnDetail(
                sprintf('Source file [%s] doesn\'t exists', transform_realpath_to_relative($from, $this->workingPath)),
                '<fg=yellow;options=bold>SKIPPED</>',
            );

            return;
        }

        $location = transform_realpath_to_relative($to, $this->workingPath);

        if (! $this->force && $this->filesystem->exists($to)) {
            $this->components?->twoColumnDetail(
                sprintf('File [%s] already exists', $location),
                '<fg=yellow;options=bold>SKIPPED</>',
            );

            return;
        }

        if ($this->confirmation === true && confirm(sprintf('Generate [%s] file?', $location)) === false) {
            return;
        }

        $this->filesystem->copy($from, $to);

        $gitKeepFile = join_paths(dirname($to), '.gitkeep');

        if ($this->filesystem->exists($gitKeepFile)) {
            $this->filesystem->delete($gitKeepFile);
        }

        $this->components?->task(sprintf('File [%s] generated', $location));
    }
}
