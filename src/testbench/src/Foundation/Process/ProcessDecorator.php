<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Process;

use Closure;
use Hypervel\Support\Traits\ForwardsCalls;
use Symfony\Component\Process\Process;

/**
 * Decorates a Symfony Process to return ProcessResult when terminated.
 *
 * @internal
 *
 * @mixin Process
 */
final class ProcessDecorator
{
    use ForwardsCalls;

    /**
     * Create a new process decorator instance.
     *
     * @param Process $process The underlying Symfony process
     * @param array<int, string>|Closure|string $command The original command
     */
    public function __construct(
        protected Process $process,
        protected Closure|array|string $command,
    ) {
    }

    /**
     * Handle dynamic calls to the process instance.
     *
     * @return $this|ProcessResult
     */
    public function __call(string $method, array $parameters): mixed
    {
        $response = $this->forwardDecoratedCallTo($this->process, $method, $parameters);

        if ($response instanceof self && $this->process->isTerminated()) {
            return new ProcessResult($this->process, $this->command);
        }

        return $response;
    }
}
