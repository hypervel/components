<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Ai;

use JsonSerializable;

class AiMessage implements JsonSerializable
{
    /**
     * Create a message with its content parts.
     *
     * @param AiMessagePart[] $parts
     */
    public function __construct(private string $role, private array $parts = [])
    {
    }

    /**
     * Return the content parts.
     *
     * @return AiMessagePart[]
     */
    public function getParts(): array
    {
        return $this->parts;
    }

    /**
     * Return the message in Sentry's serialized format.
     *
     * @return array{role: string, parts: AiMessagePart[]}
     */
    public function jsonSerialize(): array
    {
        return [
            'role' => $this->role,
            'parts' => $this->parts,
        ];
    }
}
