<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Process\Contracts\ProcessResult as ProcessResultContract;
use OutOfBoundsException;

class FakeProcessSequence
{
    /**
     * The response that should be returned when the sequence is empty.
     */
    protected FakeProcessDescription|ProcessResultContract|null $emptyProcess = null;

    /**
     * Create a new fake process sequence instance.
     *
     * @param array<int, mixed> $processes initial processes to add to the sequence
     * @param bool $failWhenEmpty indicates that invoking this sequence when it is empty should throw an exception
     */
    public function __construct(
        protected array $processes = [],
        protected bool $failWhenEmpty = true
    ) {
    }

    /**
     * Push a new process result or description onto the sequence.
     */
    public function push(array|FakeProcessDescription|ProcessResultContract|string $process): static
    {
        $this->processes[] = $this->toProcessResult($process);

        return $this;
    }

    /**
     * Make the sequence return a default result when it is empty.
     */
    public function whenEmpty(array|FakeProcessDescription|ProcessResultContract|string $process): static
    {
        $this->failWhenEmpty = false;
        $this->emptyProcess = $this->toProcessResult($process);

        return $this;
    }

    /**
     * Convert the given result into an actual process result or description.
     */
    protected function toProcessResult(array|FakeProcessDescription|ProcessResultContract|string $process): FakeProcessDescription|ProcessResultContract
    {
        return is_array($process) || is_string($process)
                ? new FakeProcessResult(output: $process)
                : $process;
    }

    /**
     * Make the sequence return a default result when it is empty.
     */
    public function dontFailWhenEmpty(): static
    {
        return $this->whenEmpty(new FakeProcessResult());
    }

    /**
     * Indicate that this sequence has depleted all of its process results.
     */
    public function isEmpty(): bool
    {
        return count($this->processes) === 0;
    }

    /**
     * Get the next process in the sequence.
     *
     * @throws OutOfBoundsException
     */
    public function __invoke(): FakeProcessDescription|ProcessResultContract
    {
        if ($this->failWhenEmpty && count($this->processes) === 0) {
            throw new OutOfBoundsException('A process was invoked, but the process result sequence is empty.');
        }

        if (! $this->failWhenEmpty && count($this->processes) === 0) {
            return $this->emptyProcess ?? new FakeProcessResult();
        }

        return array_shift($this->processes);
    }
}
