<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Transport;

use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Enums\RequestHeader;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use stdClass;

class JsonRpcRequest
{
    /**
     * @internal
     */
    public ?string $source = null;

    /**
     * Create a JSON-RPC request.
     *
     * @param array<string, mixed> $params
     */
    public function __construct(
        public int|string $id,
        public string $method,
        public array $params,
    ) {
    }

    /**
     * Parse a JSON-RPC request.
     *
     * @param array{id: mixed, jsonrpc?: mixed, method?: mixed, params?: mixed} $jsonRequest
     *
     * @throws JsonRpcException
     */
    public static function from(array $jsonRequest): static
    {
        $requestId = $jsonRequest['id'];

        if (! is_int($jsonRequest['id']) && ! is_string($jsonRequest['id'])) {
            throw new JsonRpcException('Invalid Request: The [id] member must be a string, number.', -32600, $requestId);
        }

        if (! isset($jsonRequest['jsonrpc']) || $jsonRequest['jsonrpc'] !== '2.0') {
            throw new JsonRpcException('Invalid Request: The [jsonrpc] member must be exactly [2.0].', -32600, $requestId);
        }

        if (! isset($jsonRequest['method']) || ! is_string($jsonRequest['method'])) {
            throw new JsonRpcException('Invalid Request: The [method] member is required and must be a string.', -32600, $requestId);
        }

        if (array_key_exists('params', $jsonRequest) && ! self::isObject($jsonRequest['params'])) {
            throw new JsonRpcException('Invalid params: The [params] member must be an object.', -32602, $requestId);
        }

        return new static(
            id: $requestId,
            method: $jsonRequest['method'],
            params: $jsonRequest['params'] ?? [],
        );
    }

    /**
     * Determine whether a decoded value represents a parameter object.
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * Get the pagination cursor.
     */
    public function cursor(): ?string
    {
        $cursor = $this->get('cursor');

        if ($cursor !== null && ! is_string($cursor)) {
            throw new JsonRpcException('Invalid params: The [cursor] member must be a string.', -32602, $this->id);
        }

        return $cursor;
    }

    /**
     * Get the requested positive page size.
     */
    public function perPage(): ?int
    {
        $perPage = $this->get('per_page');

        if ($perPage !== null && (! is_int($perPage) || $perPage < 1)) {
            throw new JsonRpcException('Invalid params: The [per_page] member must be a positive integer.', -32602, $this->id);
        }

        return $perPage;
    }

    /**
     * Get a request parameter.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get the request metadata.
     *
     * @return null|array<string, mixed>
     */
    public function meta(): ?array
    {
        return isset($this->params['_meta']) && self::isObject($this->params['_meta']) ? $this->params['_meta'] : null;
    }

    /**
     * Determine whether the request uses a legacy protocol version.
     */
    public function isLegacy(): bool
    {
        $meta = $this->meta() ?? [];

        return ! array_key_exists(MetaKey::PROTOCOL_VERSION->value, $meta)
            && ! array_key_exists(MetaKey::CLIENT_CAPABILITIES->value, $meta);
    }

    /**
     * Get the HTTP headers mirroring request parameters.
     *
     * @return array<string, string>
     */
    public function mirroredHeaders(): array
    {
        $headers = [RequestHeader::METHOD->value => $this->method];

        if (($name = $this->name()) !== null) {
            $headers[RequestHeader::NAME->value] = (string) new HeaderValue($name);
        }

        return $headers;
    }

    /**
     * Get the tool, prompt or resource name.
     */
    public function name(): ?string
    {
        $key = $this->nameKey();

        if ($key === null) {
            return null;
        }

        $name = $this->get($key);

        return is_string($name) ? $name : null;
    }

    /**
     * Determine whether the method requires a name header.
     */
    public function requiresName(): bool
    {
        return $this->nameKey() !== null;
    }

    /**
     * Get the parameter mirrored by the name header.
     */
    private function nameKey(): ?string
    {
        return match ($this->method) {
            'tools/call', 'prompts/get' => 'name',
            'resources/read' => 'uri',
            default => null,
        };
    }

    /**
     * Convert the protocol arguments to an application request.
     */
    public function toRequest(): Request
    {
        if (array_key_exists('arguments', $this->params)) {
            $arguments = $this->params['arguments'];

            if (! is_array($arguments) && ! $arguments instanceof stdClass) {
                throw new JsonRpcException('Invalid params: The [arguments] member must be an object.', -32602, $this->id);
            }
        } else {
            $arguments = [];
        }

        $request = new Request((array) $arguments, $this->meta());
        $request->setSource($this->source);

        if (! $arguments instanceof stdClass && ! $request->isObject()) {
            throw new JsonRpcException('Invalid params: The [arguments] member must be an object.', -32602, $this->id);
        }

        return $request;
    }

    /**
     * Convert the request to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $this->id,
            'method' => $this->method,
            ...$this->params === [] ? [] : ['params' => $this->params],
        ];
    }

    /**
     * Encode the request as JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
