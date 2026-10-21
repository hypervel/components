<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Prompts;

use Hypervel\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class Argument implements Arrayable
{
    /**
     * Create a prompt argument.
     */
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = false,
    ) {
    }

    /**
     * Convert the argument to an array.
     *
     * @return array{name: string, description: string, required: bool}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'required' => $this->required,
        ];
    }
}
