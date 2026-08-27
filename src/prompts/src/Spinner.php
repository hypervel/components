<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Prompts\Support\PromptAnimation;
use RuntimeException;
use Throwable;

class Spinner extends Prompt
{
    /**
     * How long to wait between rendering each frame.
     */
    public int $interval = 100;

    /**
     * The number of times the spinner has been rendered.
     */
    public int $count = 0;

    /**
     * Whether the spinner can only be rendered once.
     */
    public bool $static = false;

    /**
     * Create a new Spinner instance.
     */
    public function __construct(public string $message = '')
    {
    }

    /**
     * Render the spinner and execute the callback.
     *
     * @template TReturn of mixed
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    public function spin(Closure $callback): mixed
    {
        $this->static = false;
        $this->count = 0;
        $this->state = 'initial';
        $this->prevFrame = '';
        $this->capturePreviousNewLines();

        if (! static::output()->isDecorated() || ! Coroutine::inCoroutine()) {
            return $this->renderStatically($callback);
        }

        $animation = null;
        $operationFailure = null;

        try {
            $this->hideCursor();
            $this->render();

            $animation = new PromptAnimation(
                render: function (): void {
                    ++$this->count;
                    $this->render();
                },
                interval: $this->interval,
            );
            $animation->start();

            return $callback();
        } catch (Throwable $exception) {
            $operationFailure = $exception;

            throw $exception;
        } finally {
            $animationFailure = null;

            try {
                $animationFailure = $animation?->stop();
            } catch (Throwable $exception) {
                $animationFailure = $exception;
            }

            $cleanupFailure = $this->settleOperation();

            if ($operationFailure === null) {
                if ($animationFailure !== null) {
                    throw $animationFailure;
                }

                if ($cleanupFailure !== null) {
                    throw $cleanupFailure;
                }
            }
        }
    }

    /**
     * Render a static version of the spinner.
     *
     * @template TReturn of mixed
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    protected function renderStatically(Closure $callback): mixed
    {
        $this->static = true;
        $operationFailure = null;

        try {
            $this->hideCursor();
            $this->render();

            return $callback();
        } catch (Throwable $exception) {
            $operationFailure = $exception;

            throw $exception;
        } finally {
            $cleanupFailure = $this->settleOperation();

            if ($operationFailure === null && $cleanupFailure !== null) {
                throw $cleanupFailure;
            }
        }
    }

    /**
     * Erase the last frame and restore terminal state.
     */
    private function settleOperation(): ?Throwable
    {
        $failure = null;

        try {
            $this->eraseRenderedLines();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->restoreTerminalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    /**
     * Disable prompting for input.
     *
     * @throws RuntimeException
     */
    public function prompt(): never
    {
        throw new RuntimeException('Spinner cannot be prompted.');
    }

    /**
     * Get the current value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }

    /**
     * Clear the lines rendered by the spinner.
     */
    protected function eraseRenderedLines(): void
    {
        $lines = explode(PHP_EOL, $this->prevFrame);
        $this->moveCursor(-999, -count($lines) + 1);
        $this->eraseDown();
    }
}
