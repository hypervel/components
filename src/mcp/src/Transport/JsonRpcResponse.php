<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Transport;

use Hypervel\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<array<string, mixed>>
 */
class JsonRpcResponse implements Arrayable
{
    /**
     * Create a JSON-RPC response.
     *
     * @param array<string, mixed> $content
     */
    public function __construct(public array $content = [])
    {
    }

    /**
     * Create a result response.
     *
     * @param array<string, mixed> $result
     */
    public static function result(int|string $id, array $result): static
    {
        return new static([
            'id' => $id,
            'result' => $result === [] ? (object) [] : $result,
        ]);
    }

    /**
     * Create a notification response.
     *
     * @param array<string, mixed> $params
     */
    public static function notification(string $method, array $params): static
    {
        return new static([
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);
    }

    /**
     * Create an error response.
     */
    public static function error(string|int|null $id, int $code, string $message, mixed $data = null): static
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return new static([
            ...$id === null ? [] : ['id' => $id],
            'error' => $error,
        ]);
    }

    /**
     * Convert the response to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'jsonrpc' => '2.0',
            ...$this->content,
        ];
    }

    /**
     * Encode the response as JSON, replacing invalid text bytes.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
