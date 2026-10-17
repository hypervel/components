<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\Schema;

use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Support\Arr;

class DiscoverResult
{
    /**
     * Create a server discovery result.
     *
     * @param array<int, string> $supportedVersions
     * @param array<string, mixed> $capabilities
     */
    public function __construct(
        public array $supportedVersions,
        public array $capabilities,
        public ?Implementation $serverInfo = null,
        public ?string $instructions = null,
    ) {
    }

    /**
     * Parse a server discovery response.
     *
     * @param array<string, mixed> $payload
     */
    public static function from(array $payload): self
    {
        $supportedVersions = Arr::get($payload, 'supportedVersions');
        $capabilities = Arr::get($payload, 'capabilities');
        $instructions = Arr::get($payload, 'instructions');
        $meta = Arr::wrap(Arr::get($payload, '_meta'));

        if (! is_array($supportedVersions) || ! is_array($capabilities)) {
            throw new ClientException('Invalid discover response from server.');
        }

        return new self(
            supportedVersions: array_values(array_filter($supportedVersions, is_string(...))),
            capabilities: $capabilities,
            serverInfo: Implementation::from(Arr::get($meta, MetaKey::SERVER_INFO->value)),
            instructions: is_string($instructions) ? $instructions : null,
        );
    }
}
