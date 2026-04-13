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
     * The parsed segments of the recaller string.
     */
    private readonly array $segments;

    /**
     * Create a new recaller instance.
     */
    public function __construct(string $recaller)
    {
        $this->recaller = @unserialize($recaller, ['allowed_classes' => false]) ?: $recaller;
        $this->segments = explode('|', $this->recaller);
    }

    /**
     * Get the user ID from the recaller.
     */
    public function id(): string
    {
        return $this->segments[0];
    }

    /**
     * Get the "remember token" token from the recaller.
     */
    public function token(): string
    {
        return $this->segments[1];
    }

    /**
     * Get the password hash from the recaller.
     */
    public function hash(): string
    {
        return $this->segments[2];
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
        return count($this->segments) > 1;
    }

    /**
     * Determine if the recaller has all segments.
     */
    protected function hasAllSegments(): bool
    {
        return count($this->segments) >= 3
            && trim($this->segments[0]) !== ''
            && trim($this->segments[1]) !== '';
    }

    /**
     * Get the recaller's segments.
     */
    public function segments(): array
    {
        return $this->segments;
    }
}
