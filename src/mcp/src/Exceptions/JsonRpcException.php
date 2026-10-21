<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Exceptions;

use Exception;
use Hypervel\Mcp\Transport\JsonRpcResponse;

class JsonRpcException extends Exception
{
    /**
     * Create a JSON-RPC exception.
     */
    public function __construct(
        string $message,
        int $code,
        protected mixed $requestId = null,
        protected mixed $data = null
    ) {
        parent::__construct($message, $code);
    }

    /**
     * Get the error data.
     */
    public function data(): mixed
    {
        return $this->data;
    }

    /**
     * Convert the exception to a JSON-RPC response.
     */
    public function toJsonRpcResponse(): JsonRpcResponse
    {
        $id = is_string($this->requestId) || is_int($this->requestId)
            ? $this->requestId
            : null;

        return JsonRpcResponse::error(
            id: $id,
            code: $this->getCode(),
            message: $this->getMessage(),
            data: $this->data,
        );
    }
}
