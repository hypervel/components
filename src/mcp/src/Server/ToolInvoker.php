<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\Content\StructuredContentText;
use Hypervel\Mcp\Server\Contracts\Errable;
use Hypervel\Mcp\Server\Methods\Concerns\InteractsWithResponses;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Support\Collection;

class ToolInvoker implements Errable
{
    use InteractsWithResponses;

    /**
     * Create a tool invoker with the structured-content policy.
     */
    public function __construct(protected bool $mirrorStructuredContent = true)
    {
    }

    /**
     * Invoke a tool and serialize its responses.
     *
     * @return Generator<int, JsonRpcResponse>|JsonRpcResponse
     */
    public function invoke(Tool $tool, JsonRpcRequest $request): Generator|JsonRpcResponse
    {
        // @phpstan-ignore-next-line
        $response = $this->callHandler(fn (): mixed => Container::getInstance()->call([$tool, 'handle']), $request);

        return is_iterable($response)
            ? $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($tool))
            : $this->toJsonRpcResponse($request, $response, $this->serializable($tool));
    }

    /**
     * Create the serializer for a tool result.
     *
     * @return callable(ResponseFactory): array<string, mixed>
     */
    protected function serializable(Tool $tool): callable
    {
        return fn (ResponseFactory $factory): array => $factory->mergeStructuredContent(
            $factory->mergeMeta([
                'content' => $factory->responses()
                    ->unless($this->mirrorStructuredContent, fn (Collection $responses): Collection => $responses->reject(
                        fn (Response $response): bool => $response->content() instanceof StructuredContentText
                    ))
                    ->map(fn (Response $response): array => $response->content()->toTool($tool))
                    ->values()
                    ->all(),
                'isError' => $factory->responses()->contains(fn (Response $response): bool => $response->isError()),
            ])
        );
    }
}
