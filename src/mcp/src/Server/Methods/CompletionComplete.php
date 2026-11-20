<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Methods;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
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

        // Unlike upstream, these checks do not use the validator. It skips type rules for blank
        // strings, so a blank [ref] or [context.arguments] would reach the typed parameters below.
        if (! is_array($ref) || ! is_string($ref['name'] ?? '') || ! is_string($ref['uri'] ?? '')) {
            throw new JsonRpcException('The [ref] parameter must be an object with string [name] and [uri] members.', -32602, $request->id);
        }

        if (! is_array($argument) || ! is_string($argument['name'] ?? '') || ! is_string(Arr::get($argument, 'value', ''))) {
            throw new JsonRpcException('The [argument] parameter must be an object with string [name] and [value] members.', -32602, $request->id);
        }

        $completionContext = $request->get('context', []);
        $contextArguments = is_array($completionContext) ? Arr::get($completionContext, 'arguments', []) : null;

        if (! is_array($contextArguments) || ! array_all($contextArguments, fn (mixed $value): bool => is_string($value))) {
            throw new JsonRpcException('The [context] parameter must be an object whose [arguments] member contains strings.', -32602, $request->id);
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

        try {
            $result = $this->invokeCompletion($primitive, $argumentName, $argumentValue, $contextArguments);
        } catch (Exception $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }

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
        return match (Arr::get($ref, 'type')) {
            'ref/prompt' => $this->resolvePrompt(Arr::get($ref, 'name'), $context),
            'ref/resource' => $this->resolveResource(Arr::get($ref, 'uri'), $context),
            default => throw new InvalidArgumentException('Invalid reference type. Expected ref/prompt or ref/resource.'),
        };
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
