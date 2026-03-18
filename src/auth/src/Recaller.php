<?php

declare(strict_types=1);

namespace Hypervel\Auth;

class Recaller
{
    /**
     * The "recaller" / "remember me" cookie string.
     */
    protected string $recaller;

    /**
     * Create a new recaller instance.
     */
    public function __construct(string $recaller)
    {
        $this->recaller = @unserialize($recaller, ['allowed_classes' => false]) ?: $recaller;
    }

    /**
     * Get the user ID from the recaller.
     */
    public function id(): string
    {
        return explode('|', $this->recaller, 3)[0];
    }

    /**
     * Get the "remember token" token from the recaller.
     */
    public function token(): string
    {
        return explode('|', $this->recaller, 3)[1];
    }

    /**
     * Get the password hash from the recaller.
     */
    public function hash(): string
    {
        return explode('|', $this->recaller, 4)[2];
    }

    /**
     * Determine if the recaller is valid.
     */
    public function valid(): bool
    {
        return $this->properString() && $this->hasAllSegments();
    }

    /**
     * Determine if the recaller is a proper string.
     */
    protected function properString(): bool
    {
        return str_contains($this->recaller, '|');
    }

    /**
     * Determine if the recaller has all segments.
     */
    protected function hasAllSegments(): bool
    {
        $segments = explode('|', $this->recaller);

        return count($segments) >= 3 && trim($segments[0]) !== '' && trim($segments[1]) !== '';
    }

    /**
     * Get the recaller's segments.
     */
    public function segments(): array
    {
        return explode('|', $this->recaller);
    }
}
