<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;

class Notification implements Content
{
    use HasMeta;

    /**
     * Create notification content.
     *
     * @param array<string, mixed> $params
     */
    public function __construct(protected string $method, protected array $params)
    {
    }

    /**
     * Format the notification for a tool response.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        return $this->toArray();
    }

    /**
     * Format the notification for a prompt response.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        return $this->toArray();
    }

    /**
     * Format the notification for a resource response.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        return $this->toArray();
    }

    /**
     * Get the notification method.
     */
    public function __toString(): string
    {
        return $this->method;
    }

    /**
     * Convert the notification to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $params = $this->params;

        if ($this->meta !== null && $this->meta !== [] && ! isset($params['_meta'])) {
            $params['_meta'] = $this->meta;
        }

        return [
            'method' => $this->method,
            'params' => $params,
        ];
    }
}
