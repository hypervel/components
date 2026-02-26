<?php

declare(strict_types=1);

namespace Hypervel\Prompts\Concerns;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Prompts\Exceptions\NonInteractiveValidationException;

trait Interactivity
{
    /**
     * Whether to render the prompt interactively.
     */
    protected static bool $interactive;

    /**
     * Set interactive mode.
     */
    public static function interactive(bool $interactive = true): void
    {
        if (Coroutine::inCoroutine()) {
            Context::set('__prompt.interactive', $interactive);
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
            return Context::get('__prompt.interactive') ?? (isset(static::$interactive) ? static::$interactive : null);
        }

        return isset(static::$interactive) ? static::$interactive : null;
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
