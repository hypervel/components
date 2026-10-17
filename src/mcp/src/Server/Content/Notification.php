<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Content;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use InvalidArgumentException;

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
     * Reject a notification used as tool result content.
     *
     * @return array<string, mixed>
     */
    public function toTool(Tool $tool): array
    {
        throw new InvalidArgumentException('Notifications must be yielded separately from tool result content.');
    }

    /**
     * Reject a notification used as prompt result content.
     *
     * @return array<string, mixed>
     */
    public function toPrompt(Prompt $prompt): array
    {
        throw new InvalidArgumentException('Notifications must be yielded separately from prompt result content.');
    }

    /**
     * Reject a notification used as resource result content.
     *
     * @return array<string, mixed>
     */
    public function toResource(Resource $resource): array
    {
        throw new InvalidArgumentException('Notifications must be yielded separately from resource result content.');
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
        $meta = [...($this->meta ?? []), ...($params['_meta'] ?? [])];

        if ($meta !== []) {
            $params['_meta'] = $meta;
        }

        return [
            'method' => $this->method,
            'params' => $params,
        ];
    }
}
