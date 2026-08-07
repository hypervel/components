<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Traversable;
use WeakReference;

/**
 * @template TSteps of iterable<mixed>|int
 */
class Progress extends Prompt
{
    /**
     * The current progress bar item count.
     */
    public int $progress = 0;

    /**
     * The total number of steps.
     */
    public int $total = 0;

    /**
     * The original value of pcntl_async_signals.
     */
    protected ?bool $originalAsync = null;

    /**
     * The original SIGINT handler.
     */
    protected mixed $originalSignalHandler = null;

    /**
     * Create a new ProgressBar instance.
     *
     * @param TSteps $steps
     */
    public function __construct(public string $label, public int|iterable $steps, public string $hint = '')
    {
        if ($this->steps instanceof Traversable && ! is_countable($this->steps)) {
            $this->steps = iterator_to_array($this->steps, false); // @phpstan-ignore assign.propertyType (PHPStan cannot preserve the generic iterable property type after materialization.)
        }

        $this->total = match (true) { // @phpstan-ignore assign.propertyType (PHPStan does not follow the normalized iterable through the match.)
            is_int($this->steps) => $this->steps,
            is_countable($this->steps) => count($this->steps),
            default => throw new InvalidArgumentException('Unable to count steps.'),
        };

        if ($this->total <= 0) {
            throw new InvalidArgumentException('Progress bar must have at least one item.');
        }
    }

    /**
     * Map over the steps while rendering the progress bar.
     *
     * @template TReturn
     *
     * @param Closure((TSteps is int ? int : value-of<TSteps>), $this): TReturn $callback
     * @return array<TReturn>
     */
    public function map(Closure $callback): array
    {
        $this->start();

        $result = [];

        try {
            if (is_int($this->steps)) {
                for ($i = 0; $i < $this->steps; ++$i) {
                    $result[] = $callback($i, $this);
                    $this->advance();
                }
            } else {
                foreach ($this->steps as $step) {
                    $result[] = $callback($step, $this);
                    $this->advance();
                }
            }

            if ($this->hint !== '') {
                // Just pause for one moment to show the final hint
                // so it doesn't look like it was skipped
                usleep(250_000);
            }
        } catch (Throwable $e) {
            $this->state = 'error';

            try {
                $this->renderTerminalFrame();
            } catch (Throwable) {
                // The callback failure remains primary while settlement continues.
            }

            $this->settleOperation($e);

            throw $e;
        }

        $this->finish();

        return $result;
    }

    /**
     * Start the progress bar.
     */
    public function start(): void
    {
        $this->progress = 0;
        $this->state = 'initial';
        $this->prevFrame = '';
        $this->capturePreviousNewLines();

        if (function_exists('pcntl_signal')) {
            $this->originalSignalHandler = pcntl_signal_get_handler(SIGINT);
            $this->originalAsync = pcntl_async_signals(true);
            $progress = WeakReference::create($this);

            // Weak ownership lets an abandoned manual Progress reach destructor cleanup.
            pcntl_signal(SIGINT, static function () use ($progress): void {
                $instance = $progress->get();

                if ($instance instanceof self) {
                    $instance->state = 'cancel';

                    try {
                        $instance->renderTerminalFrame();
                    } catch (Throwable) {
                        // Cancellation rendering is best effort before the process exits.
                    }

                    $instance->settleOperation();
                }

                exit;
            });
        }

        try {
            $this->hideCursor();

            if (static::output()->isDecorated()) {
                $this->render();
            }

            $this->state = 'active';
        } catch (Throwable $exception) {
            $this->settleOperation($exception);

            throw $exception;
        }
    }

    /**
     * Advance the progress bar.
     */
    public function advance(int $step = 1): void
    {
        $this->progress += $step;

        if ($this->progress > $this->total) {
            $this->progress = $this->total;
        }

        if (static::output()->isDecorated()) {
            $this->render();
        }
    }

    /**
     * Finish the progress bar.
     */
    public function finish(): void
    {
        $failure = null;

        try {
            $this->state = 'submit';
            $this->renderTerminalFrame();
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $failure = $this->settleOperation($failure);

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * Restore signal and terminal state for one progress operation.
     */
    private function settleOperation(?Throwable $failure = null): ?Throwable
    {
        $this->resetSignals();

        try {
            $this->restoreTerminalState();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    /**
     * Force the progress bar to re-render.
     */
    public function render(): void
    {
        parent::render();
    }

    /**
     * Render the terminal frame for the current progress operation.
     */
    protected function renderTerminalFrame(): void
    {
        if (static::output()->isDecorated()) {
            $this->render();

            return;
        }

        $frame = $this->renderTheme();

        static::output()->write($frame);
        $this->prevFrame = $frame;
    }

    /**
     * Update the label.
     */
    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Update the hint.
     */
    public function hint(string $hint): static
    {
        $this->hint = $hint;

        return $this;
    }

    /**
     * Get the completion percentage.
     */
    public function percentage(): float|int
    {
        return $this->progress / $this->total;
    }

    /**
     * Disable prompting for input.
     *
     * @throws RuntimeException
     */
    public function prompt(): never
    {
        throw new RuntimeException('Progress Bar cannot be prompted.');
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }

    /**
     * Reset the signal handling.
     */
    protected function resetSignals(): void
    {
        if ($this->originalAsync === null) {
            return;
        }

        /** @var callable|int $handler */
        $handler = $this->originalSignalHandler;

        // Exact restoration prevents process-global signal state from replacing another owner.
        pcntl_signal(SIGINT, $handler);
        pcntl_async_signals($this->originalAsync);

        $this->originalSignalHandler = null;
        $this->originalAsync = null;
    }

    /**
     * Restore the cursor.
     */
    public function __destruct()
    {
        $this->resetSignals();

        parent::__destruct();
    }
}
