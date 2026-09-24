<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions;

use Hypervel\Support\Traits\ReflectsClosures;
use Throwable;

class ReportableHandler
{
    use ReflectsClosures;

    /**
     * The underlying callback.
     *
     * @var callable
     */
    protected $callback;

    /**
     * Indicates if reporting should stop after invoking this handler.
     */
    protected bool $shouldStop = false;

    /**
     * Create a new reportable handler instance.
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Invoke the handler.
     */
    public function __invoke(Throwable $e): bool
    {
        $result = call_user_func($this->callback, $e);

        if ($result === false) {
            return false;
        }

        return ! $this->shouldStop;
    }

    /**
     * Determine if the callback handles the given exception.
     */
    public function handles(Throwable $e): bool
    {
        foreach ($this->firstClosureParameterTypes($this->callback) as $type) {
            if (is_a($e, $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indicate that report handling should stop after invoking this callback.
     *
     * Boot-only. The flag persists on the registered callback and stops report
     * handling for every exception it subsequently handles in the worker.
     *
     * @return $this
     */
    public function stop(): static
    {
        $this->shouldStop = true;

        return $this;
    }
}
