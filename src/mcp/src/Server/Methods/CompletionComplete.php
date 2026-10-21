<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Completions\CompletionResponse;
use Hypervel\Mcp\Server\Contracts\Completable;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Methods\Concerns\ResolvesPrompts;
use Hypervel\Mcp\Server\Methods\Concerns\ResolvesResources;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;

class CompletionComplete implements Method
{
    use ResolvesPrompts;
    use ResolvesResources;

    /**
     * Complete an argument for a prompt or resource.
     */
    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        if (! $context->hasCapability(Server::CAPABILITY_COMPLETIONS)) {
            throw new JsonRpcException(
                'Server does not support completions capability.',
                -32601,
                $request->id,
            );
        }

        $ref = $request->get('ref');
        $argument = $request->get('argument');

        if (is_null($ref) || is_null($argument)) {
            throw new JsonRpcException(
                'Missing required parameters: ref and argument',
                -32602,
                $request->id,
            );
        }

        if (! is_array($ref) || ! is_array($argument)) {
            throw new JsonRpcException('The [ref] and [argument] parameters must be objects.', -32602, $request->id);
        }

        try {
            $primitive = $this->resolvePrimitive($ref, $context);
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw new JsonRpcException($invalidArgumentException->getMessage(), -32602, $request->id);
        }

        if (! $primitive instanceof Completable) {
            $result = CompletionResponse::empty();

            return JsonRpcResponse::result($request->id, [
                'completion' => $result->toArray(),
            ]);
        }

        $argumentName = Arr::get($argument, 'name');
        $argumentValue = Arr::get($argument, 'value', '');

        if (is_null($argumentName)) {
            throw new JsonRpcException(
                'Missing argument name.',
                -32602,
                $request->id,
            );
        }

        if (! is_string($argumentName) || ! is_string($argumentValue)) {
            throw new JsonRpcException('The argument [name] and [value] members must be strings.', -32602, $request->id);
        }

        $completionContext = $request->get('context', []);

        if (! is_array($completionContext) || ! is_array($contextArguments = Arr::get($completionContext, 'arguments', []))) {
            throw new JsonRpcException('The [context] and its [arguments] member must be objects.', -32602, $request->id);
        }

        try {
            $result = $this->invokeCompletion($primitive, $argumentName, $argumentValue, $contextArguments);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Exception) {
            $result = CompletionResponse::empty();
        }

        return JsonRpcResponse::result($request->id, [
            'completion' => $result->toArray(),
        ]);
    }

    /**
     * Resolve the referenced completion provider.
     *
     * @param array<string, mixed> $ref
     */
    protected function resolvePrimitive(array $ref, ServerContext $context): Prompt|Resource|HasUriTemplate
    {
        $type = Arr::get($ref, 'type');
        $key = match ($type) {
            'ref/prompt' => 'name',
            'ref/resource' => 'uri',
            default => throw new InvalidArgumentException('Invalid reference type. Expected ref/prompt or ref/resource.'),
        };
        $name = Arr::get($ref, $key);

        if ($name !== null && ! is_string($name)) {
            throw new InvalidArgumentException("The reference [{$key}] member must be a string.");
        }

        return $type === 'ref/prompt'
            ? $this->resolvePrompt($name, $context)
            : $this->resolveResource($name, $context);
    }

    /**
     * Invoke the completion provider with resolved arguments.
     *
     * @param array<string, mixed> $context
     */
    protected function invokeCompletion(
        Completable $primitive,
        string $argumentName,
        string $argumentValue,
        array $context
    ): mixed {
        $container = Container::getInstance();

        $result = $container->call($primitive->complete(...), [
            'argument' => $argumentName,
            'value' => $argumentValue,
            'context' => $context,
        ]);

        return $result->resolve($argumentValue);
    }
}
