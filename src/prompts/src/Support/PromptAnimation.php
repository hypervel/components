<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Support;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Channel;
use Throwable;

/**
 * Own one interruptible prompt animation coroutine and its render failure.
 *
 * @internal
 */
class PromptAnimation
{
    /**
     * The stop signal consumed by the animation coroutine.
     *
     * @var Channel<true>
     */
    private Channel $stop;

    private ?int $coroutineId = null;

    private ?Throwable $renderFailure = null;

    /**
     * Create a new prompt animation owner.
     */
    public function __construct(
        private readonly Closure $render,
        private readonly int $interval,
    ) {
        $this->stop = new Channel(1);
    }

    /**
     * Start the animation coroutine.
     */
    public function start(): void
    {
        Coroutine::forkOwned(function (): void {
            try {
                while ($this->stop->pop($this->interval / 1000) === false) {
                    ($this->render)();
                }
            } catch (Throwable $exception) {
                $this->renderFailure = $exception;
            }
        }, function (Closure $run): void {
            $this->coroutineId = Coroutine::id();
            $run();
        });
    }

    /**
     * Stop and join the animation coroutine.
     */
    public function stop(): ?Throwable
    {
        if ($this->coroutineId === null) {
            return $this->renderFailure;
        }

        $coroutineId = $this->coroutineId;
        $this->coroutineId = null;

        $this->stop->push(true);
        Coroutine::join([$coroutineId]);

        return $this->renderFailure;
    }
}
