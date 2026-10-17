<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Primitives;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Schema\ToolResult;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Mcp\Support\MirroredParameters;
use Hypervel\Support\Arr;

class Tool
{
    protected MirroredParameters $mirroredParameters;

    /**
     * Create a remote tool description.
     *
     * @param array<string, mixed> $inputSchema
     * @param null|array<string, mixed> $outputSchema
     * @param array<string, mixed> $annotations
     * @param null|array<string, mixed> $meta
     *
     * @throws MirroredParameterException
     */
    public function __construct(
        protected ?Client $client,
        public readonly string $name,
        public readonly ?string $title,
        public readonly ?string $description,
        public readonly array $inputSchema,
        public readonly ?array $outputSchema,
        public readonly array $annotations,
        public readonly ?array $meta,
    ) {
        $this->mirroredParameters = MirroredParameters::fromSchema($inputSchema);
    }

    /**
     * Get the tool's mirrored request parameters.
     */
    public function mirroredParameters(): MirroredParameters
    {
        return $this->mirroredParameters;
    }

    /**
     * Create a tool from its server payload.
     *
     * @param array<string, mixed> $payload
     */
    public static function from(?Client $client, array $payload): self
    {
        $name = Arr::get($payload, 'name');
        $title = Arr::get($payload, 'title');
        $description = Arr::get($payload, 'description');
        $inputSchema = Arr::get($payload, 'inputSchema', []);
        $outputSchema = Arr::get($payload, 'outputSchema');
        $annotations = Arr::get($payload, 'annotations', []);
        $meta = Arr::get($payload, '_meta');

        if (! is_string($name) || blank($name)
            || ! is_array($inputSchema)
            || ! is_array($annotations)
            || (! is_null($title) && ! is_string($title))
            || (! is_null($description) && ! is_string($description))
            || (! is_null($outputSchema) && ! is_array($outputSchema))
            || (! is_null($meta) && ! is_array($meta))) {
            throw new ClientException('Invalid tool payload from server.');
        }

        return new self(
            client: $client,
            name: $name,
            title: $title,
            description: $description,
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            annotations: $annotations,
            meta: $meta,
        );
    }

    /**
     * Call the tool through its bound client.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(array $arguments = []): ToolResult
    {
        if (! $this->client instanceof Client) {
            throw new ClientException("Tool [{$this->name}] is not bound to a client.");
        }

        return $this->client->callTool($this, $arguments);
    }
}
