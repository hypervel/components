<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Generator;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\Contracts\Errable;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\ToolInvoker;
use Hypervel\Mcp\Support\MirroredParameters;
use Hypervel\Mcp\Transport\HeaderValue;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Symfony\Component\HttpFoundation\HeaderBag;

class CallTool implements Errable, Method
{
    /**
     * Call the requested tool.
     *
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if (is_null($request->get('name'))) {
            throw new JsonRpcException(
                'Missing [name] parameter.',
                -32602,
                $request->id,
            );
        }

        if (! is_string($request->get('name'))) {
            throw new JsonRpcException('The [name] parameter must be a string.', -32602, $request->id);
        }

        $tool = $context
            ->tools()
            ->first(
                fn (Tool $tool): bool => $tool->name() === $request->params['name'],
                fn (): never => throw new JsonRpcException(
                    "Tool [{$request->params['name']}] not found.",
                    -32602,
                    $request->id,
                )
            );

        if ($context->headers instanceof HeaderBag) {
            $this->validateMirroredHeaders($tool, $request, $context->headers);
        }

        return (new ToolInvoker)->invoke($tool, $request);
    }

    /**
     * Validate mirrored parameter headers against the tool call arguments.
     *
     * @throws JsonRpcException
     */
    protected function validateMirroredHeaders(Tool $tool, JsonRpcRequest $request, HeaderBag $headers): void
    {
        $arguments = $request->get('arguments');
        $arguments = is_array($arguments) ? $arguments : [];

        foreach (MirroredParameters::fromSchema($tool->toArray()['inputSchema'] ?? [])->all() as $parameter) {
            $header = trim((string) $headers->get($parameter->header()), " \t");
            $value = $parameter->raw($arguments);

            $mismatch = match (true) {
                $value === null && $header === '' => null,
                $value === null => "The [{$parameter->header()}] header is present but the request body has no [{$parameter->path}] argument.",
                // Legacy clients never send mirrored headers, but any header that is present must still match the body.
                $header === '' => $request->isLegacy() ? null : "The [{$parameter->header()}] header is required.",
                ! HeaderValue::isSafe($header) => "The [{$parameter->header()}] header value contains invalid characters.",
                $parameter->stringify($value) === null => "The [{$parameter->path}] argument is not a valid [{$parameter->type->value}].",
                ! $parameter->matches($header, $value) => "The [{$parameter->header()}] header value does not match the request body value at [{$parameter->path}].",
                default => null,
            };

            if ($mismatch !== null) {
                throw new JsonRpcException("Header mismatch: {$mismatch}", ErrorCode::HEADER_MISMATCH->value, $request->id);
            }
        }
    }
}
