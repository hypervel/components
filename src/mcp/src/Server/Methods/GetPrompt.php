<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Methods\Concerns\InteractsWithResponses;
use Hypervel\Mcp\Server\Methods\Concerns\ResolvesPrompts;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use InvalidArgumentException;

class GetPrompt implements Method
{
    use InteractsWithResponses;
    use ResolvesPrompts;

    /**
     * Invoke the requested prompt.
     *
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $name = $request->get('name');

        if ($name !== null && ! is_string($name)) {
            throw new JsonRpcException('The [name] parameter must be a string.', -32602, $request->id);
        }

        try {
            $prompt = $this->resolvePrompt($name, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32602, $request->id);
        }

        // @phpstan-ignore-next-line
        $response = $this->callHandler(fn (): mixed => Container::getInstance()->call([$prompt, 'handle']), $request);

        return is_iterable($response)
            ? $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($prompt))
            : $this->toJsonRpcResponse($request, $response, $this->serializable($prompt));
    }

    /**
     * Create the serializer for prompt responses.
     *
     * @return callable(ResponseFactory): array<string, mixed>
     */
    protected function serializable(Prompt $prompt): callable
    {
        return fn (ResponseFactory $factory): array => $factory->mergeMeta([
            'description' => $prompt->description(),
            'messages' => $factory->responses()->map(fn (Response $response): array => [
                'role' => $response->role()->value,
                'content' => $response->content()->toPrompt($prompt),
            ])->all(),
        ]);
    }
}
