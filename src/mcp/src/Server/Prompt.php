<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Hypervel\Mcp\Server\Prompts\Argument;

abstract class Prompt extends Primitive
{
    /**
     * Get the prompt arguments.
     *
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [];
    }

    /**
     * Convert the prompt to a method call.
     *
     * @return array<string, mixed>
     */
    public function toMethodCall(): array
    {
        return ['name' => $this->name()];
    }

    /**
     * Convert the prompt to an array.
     *
     * @return array{
     *     name: string,
     *     title: string,
     *     description: string,
     *     arguments: array<int, array{name: string, description: string, required: bool}>,
     *     icons?: list<array<string, mixed>>,
     *     _meta?: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        // @phpstan-ignore return.type
        return $this->mergeMeta($this->mergeIcons([
            'name' => $this->name(),
            'title' => $this->title(),
            'description' => $this->description(),
            'arguments' => array_map(
                fn (Argument $argument): array => $argument->toArray(),
                array_values($this->arguments()),
            ),
        ]));
    }
}
