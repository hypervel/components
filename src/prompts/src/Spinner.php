<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use Hyperf\Coroutine\Coroutine;
use RuntimeException;

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
     * Indicates if the spinner has finished.
     */
    protected bool $hasFinished = false;

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
        $this->capturePreviousNewLines();

        $this->hideCursor();
        $this->render();

        Coroutine::create(function () {
            while (! $this->hasFinished) {
                $this->render();

                ++$this->count;

                usleep($this->interval * 1000);
            }
        });

        try {
            return $callback();
        } finally {
            $this->hasFinished = true;
            $this->eraseRenderedLines();
        }
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
