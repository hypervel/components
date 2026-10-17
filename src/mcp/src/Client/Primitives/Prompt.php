<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Primitives;

use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Arr;

class Prompt
{
    /**
     * Create a remote prompt description.
     *
     * @param array<int, array<string, mixed>> $arguments
     * @param null|array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly array $arguments,
        public readonly ?array $meta,
    ) {
    }

    /**
     * Create a prompt from its server payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function from(array $payload): self
    {
        $name = Arr::get($payload, 'name');
        $title = Arr::get($payload, 'title');
        $description = Arr::get($payload, 'description');
        $arguments = Arr::get($payload, 'arguments', []);
        $meta = Arr::get($payload, '_meta');

        if (! is_string($name) || blank($name)
            || ! is_array($arguments)
            || (! is_null($title) && ! is_string($title))
            || (! is_null($description) && ! is_string($description))
            || (! is_null($meta) && ! is_array($meta))) {
            throw new ClientException('Invalid prompt payload from server.');
        }

        return new self(
            name: $name,
            title: $title,
            description: $description,
            arguments: array_values(array_filter($arguments, is_array(...))),
            meta: $meta,
        );
    }
}
