<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

class PausePrompt extends Prompt
{
    /**
     * Create a new PausePrompt instance.
     */
    public function __construct(public string $message = 'Press enter to continue...')
    {
        $this->required = false;
        $this->validate = null;

        $this->on('key', fn ($key) => match ($key) {
            Key::ENTER => $this->submit(),
            default => null,
        });
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): bool
    {
        return static::$interactive;
    }
}
