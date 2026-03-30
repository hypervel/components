<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;

trait Interactivity
{
    /**
     * Context key for the interactive mode override.
     */
    protected const INTERACTIVE_CONTEXT_KEY = '__prompts.interactive';

    /**
     * Whether to render the prompt interactively.
     *
     * Null means no override — falls back to stream_isatty(STDIN).
     */
    protected static ?bool $interactive = null;

    /**
     * Set interactive mode.
     */
    public static function interactive(bool $interactive = true): void
    {
        if (Coroutine::inCoroutine()) {
            CoroutineContext::set(self::INTERACTIVE_CONTEXT_KEY, $interactive);
        } else {
            static::$interactive = $interactive;
        }
    }

    /**
     * Determine if the prompt is interactive.
     */
    public static function isInteractive(): ?bool
    {
        if (Coroutine::inCoroutine()) {
            return CoroutineContext::get(self::INTERACTIVE_CONTEXT_KEY) ?? static::$interactive;
        }

        return static::$interactive;
    }

    /**
     * Reset interactivity state to defaults.
     */
    public static function resetInteractivity(): void
    {
        static::$interactive = null;
        CoroutineContext::forget(self::INTERACTIVE_CONTEXT_KEY);
    }

    /**
     * Return the default value if it passes validation.
     */
    protected function default(): mixed
    {
        $default = $this->value();

        $this->validate($default);

        if ($this->state === 'error') {
            throw new NonInteractiveValidationException($this->error);
        }

        return $default;
    }
}
