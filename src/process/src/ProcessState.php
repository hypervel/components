<?php

declare(strict_types=1);

namespace Hypervel\Process;

use Hypervel\Context\CoroutineContext;
use Hypervel\Context\NonCopyableContext;
use Hypervel\Coroutine\Coroutine;
use Symfony\Component\Process\Process;
use WeakMap;

/**
 * Keep asynchronous processes with their starting coroutine without retaining completed processes.
 *
 * @internal
 */
class ProcessState implements NonCopyableContext
{
    public const string CONTEXT_KEY = '__process.state';

    /** @var WeakMap<Process, true> */
    public WeakMap $processes;

    /**
     * Create process state owned by one coroutine.
     */
    public function __construct()
    {
        $this->processes = new WeakMap;
    }

    /**
     * Get the current coroutine's state and register its exit cleanup once.
     */
    public static function current(): self
    {
        if ($state = CoroutineContext::get(self::CONTEXT_KEY)) {
            return $state;
        }

        $state = CoroutineContext::set(self::CONTEXT_KEY, new self);
        Coroutine::defer($state->stop(...));

        return $state;
    }

    /**
     * Stop the processes left behind when their owning coroutine exits.
     */
    public function stop(): void
    {
        $processes = [];

        foreach ($this->processes as $process => $tracked) {
            $processes[] = new InvokedProcess($process);
        }

        (new InvokedProcessPool($processes))->stop(0);
    }
}
