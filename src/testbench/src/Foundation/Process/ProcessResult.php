<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Process;

use BadMethodCallException;
use Closure;
use Hypervel\Process\ProcessResult as BaseProcessResult;
use Hypervel\Support\Traits\ForwardsCalls;
use Symfony\Component\Process\Process;

/**
 * Process result with additional passthrough methods for testbench.
 *
 * @internal
 */
final class ProcessResult extends BaseProcessResult
{
    use ForwardsCalls;

    /**
     * The methods that should be forwarded to the process instance.
     *
     * @var array<int, string>
     */
    protected array $passthru = [
        'getCommandLine',
        'getErrorOutput',
        'getExitCode',
        'getOutput',
        'isSuccessful',
    ];

    /**
     * Create a new process result instance.
     *
     * @param Process $process The underlying Symfony process
     * @param array<int, string>|Closure|string $command The original command
     */
    public function __construct(
        Process $process,
        protected Closure|array|string $command,
    ) {
        parent::__construct($process);
    }

    /**
     * Handle dynamic calls to the process instance.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (! in_array($method, $this->passthru)) {
            self::throwBadMethodCallException($method);
        }

        return $this->forwardDecoratedCallTo($this->process, $method, $parameters);
    }
}
