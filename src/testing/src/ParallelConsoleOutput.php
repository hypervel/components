<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Symfony\Component\Console\Output\ConsoleOutput;

class ParallelConsoleOutput extends ConsoleOutput
{
    /**
     * The original output instance.
     */
    protected \Symfony\Component\Console\Output\OutputInterface $output;

    /**
     * The output that should be ignored.
     *
     * @var array<int, string>
     */
    protected array $ignore = [
        'Running phpunit in',
        'Configuration read from',
    ];

    /**
     * Create a new parallel console output instance.
     */
    public function __construct(\Symfony\Component\Console\Output\OutputInterface $output)
    {
        parent::__construct(
            $output->getVerbosity(),
            $output->isDecorated(),
            $output->getFormatter(),
        );

        $this->output = $output;
    }

    /**
     * Write a message to the output.
     *
     * @param iterable|string $messages
     */
    public function write($messages, bool $newline = false, int $options = 0): void
    {
        $messages = (new Collection($messages))
            ->filter(fn ($message) => ! Str::contains($message, $this->ignore));

        $this->output->write($messages->toArray(), $newline, $options);
    }
}
