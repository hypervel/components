<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Request;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\ResponseFactory;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Methods\Concerns\InteractsWithResponses;
use Hypervel\Mcp\Server\Methods\Concerns\ResolvesResources;
use Hypervel\Mcp\Server\RequestContext;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use InvalidArgumentException;

class ReadResource implements Method
{
    use InteractsWithResponses;
    use ResolvesResources;

    /**
     * Read the requested resource.
     *
     * @return Generator<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws BindingResolutionException
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        $uri = $request->get('uri');

        if ($uri !== null && ! is_string($uri)) {
            throw new JsonRpcException('The [uri] parameter must be a string.', ErrorCode::INVALID_PARAMS->value, $request->id);
        }

        try {
            $resource = $this->resolveResource($uri, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new JsonRpcException(
                $invalidArgumentException->getMessage(),
                ErrorCode::INVALID_PARAMS->value,
                $request->id,
                $uri === null || $uri === '' ? null : ['uri' => $uri],
            );
        }

        $container = Container::getInstance();

        $resourceRequest = $container->make(Request::class);
        $resourceRequest->setUri($uri);

        if ($resource instanceof HasUriTemplate) {
            $variables = $resource->uriTemplate()->match($uri) ?? [];
            $resourceRequest->merge($variables);
        }

        $context->resolvedResource = $resource;
        $context->resolvedResourceRequest = $resourceRequest;

        return RequestContext::run($resourceRequest, function () use ($resource, $uri, $request): Generator|JsonRpcResponse {
            $response = $this->callHandler(fn (): mixed => $this->invokeResource($resource, $uri), $request);

            return is_iterable($response)
                ? $this->toJsonRpcStreamedResponse($request, $response, $this->serializable($resource, $uri))
                : $this->toJsonRpcResponse($request, $response, $this->serializable($resource, $uri));
        });
    }

    /**
     * Invoke a resource under the installed request and template arguments.
     *
     * @throws BindingResolutionException
     */
    protected function invokeResource(Resource $resource, string $uri): mixed
    {
        if ($resource instanceof AppResource) {
            CoroutineContext::set(RequestContext::LIBRARY_SCRIPTS_CONTEXT_KEY, $resource->libraryScripts());
        }

        // @phpstan-ignore-next-line
        return Container::getInstance()->call([$resource, 'handle']);
    }

    /**
     * Create the serializer for a resource response.
     *
     * @return callable(ResponseFactory): array<string, mixed>
     */
    protected function serializable(Resource $resource, string $uri): callable
    {
        $appMeta = $resource instanceof AppResource ? $resource->resolvedAppMeta() : null;

        return fn (ResponseFactory $factory): array => $factory->mergeMeta([
            'contents' => $factory->responses()->map(function (Response $response) use ($resource, $uri, $appMeta): array {
                $content = [
                    ...$response->content()->toResource($resource),
                    'uri' => $uri,
                ];

                if ($appMeta !== null && $appMeta !== []) {
                    $content['_meta'] = array_merge($content['_meta'] ?? [], [
                        'ui' => $appMeta,
                    ]);
                }

                return $content;
            })->all(),
        ]);
    }
}
