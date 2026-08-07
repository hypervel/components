<?php

declare(strict_types=1);

namespace Hypervel\Prompts;

use Hypervel\Prompts\Elements\ElementContract;

class Callout extends Prompt
{
    /**
     * Create a new Callout instance.
     *
     * @param array<int, ElementContract|string>|string $content
     */
    public function __construct(
        public string $label,
        public string|array $content,
        public ?string $type = null,
        public string $info = '',
    ) {
    }

    /**
     * Display the callout.
     */
    public function display(): void
    {
        $this->prompt();
    }

    /**
     * Display the callout.
     */
    public function prompt(): bool
    {
        $this->capturePreviousNewLines();

        if (static::shouldFallback()) {
            return $this->fallback();
        }

        $this->state = 'submit';

        static::output()->write($this->renderTheme());

        return true;
    }

    /**
     * Get the value of the prompt.
     */
    public function value(): bool
    {
        return true;
    }
}
