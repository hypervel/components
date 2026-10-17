<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Testing;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Contracts\HasUriTemplate;
use Hypervel\Mcp\Server\Primitive;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Server\Transport\FakeTransporter;
use Hypervel\Mcp\Support\UriTemplate;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use InvalidArgumentException;
use Stringable;

class PendingTestResponse
{
    /**
     * Create a pending invocation for the given server.
     *
     * @param class-string<Server> $serverClass
     */
    public function __construct(
        protected Container $app,
        protected string $serverClass
    ) {
    }

    /**
     * Retrieve every page of registered tools.
     */
    public function tools(): TestListResponse
    {
        $server = $this->initializeServer();
        $tools = [];
        $cursor = null;

        do {
            $request = new JsonRpcRequest(
                uniqid(),
                'tools/list',
                $cursor !== null ? ['cursor' => $cursor] : [],
            );

            $response = $this->executeRequest($server, $request);

            if (! $response instanceof JsonRpcResponse) {
                throw new InvalidArgumentException('Expected a JsonRpcResponse for [tools/list].');
            }

            $result = $response->toArray()['result'] ?? null;

            if (! is_array($result) || ! is_array($result['tools'] ?? null)) {
                throw new InvalidArgumentException('Invalid tools/list response from server.');
            }

            $tools = [...$tools, ...$result['tools']];
            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        return new TestListResponse($tools);
    }

    /**
     * Retrieve every page of registered resources.
     */
    public function resources(): TestListResponse
    {
        $server = $this->initializeServer();
        $resources = [];
        $cursor = null;

        do {
            $request = new JsonRpcRequest(
                uniqid(),
                'resources/list',
                $cursor !== null ? ['cursor' => $cursor] : [],
            );

            $response = $this->executeRequest($server, $request);

            if (! $response instanceof JsonRpcResponse) {
                throw new InvalidArgumentException('Expected a JsonRpcResponse for [resources/list].');
            }

            $result = $response->toArray()['result'] ?? null;

            if (! is_array($result) || ! is_array($result['resources'] ?? null)) {
                throw new InvalidArgumentException('Invalid resources/list response from server.');
            }

            $resources = [...$resources, ...$result['resources']];
            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        return new TestListResponse($resources);
    }

    /**
     * Retrieve every page of registered prompts.
     */
    public function prompts(): TestListResponse
    {
        $server = $this->initializeServer();
        $prompts = [];
        $cursor = null;

        do {
            $request = new JsonRpcRequest(
                uniqid(),
                'prompts/list',
                $cursor !== null ? ['cursor' => $cursor] : [],
            );

            $response = $this->executeRequest($server, $request);

            if (! $response instanceof JsonRpcResponse) {
                throw new InvalidArgumentException('Expected a JsonRpcResponse for [prompts/list].');
            }

            $result = $response->toArray()['result'] ?? null;

            if (! is_array($result) || ! is_array($result['prompts'] ?? null)) {
                throw new InvalidArgumentException('Invalid prompts/list response from server.');
            }

            $prompts = [...$prompts, ...$result['prompts']];
            $cursor = is_string($result['nextCursor'] ?? null) ? $result['nextCursor'] : null;
        } while ($cursor !== null);

        return new TestListResponse($prompts);
    }

    /**
     * Invoke the given tool.
     *
     * @param class-string<Tool>|Tool $tool
     * @param array<string, mixed> $arguments
     */
    public function tool(Tool|string $tool, array $arguments = []): TestResponse
    {
        return $this->run('tools/call', $tool, $arguments);
    }

    /**
     * Invoke the given prompt.
     *
     * @param class-string<Prompt>|Prompt $prompt
     * @param array<string, mixed> $arguments
     */
    public function prompt(Prompt|string $prompt, array $arguments = []): TestResponse
    {
        return $this->run('prompts/get', $prompt, $arguments);
    }

    /**
     * Read the given resource.
     *
     * @param class-string<McpResource>|McpResource $resource
     * @param array<string, mixed> $arguments
     */
    public function resource(McpResource|string $resource, array $arguments = []): TestResponse
    {
        return $this->run('resources/read', $resource, $arguments);
    }

    /**
     * Complete an argument for the given primitive.
     *
     * @param class-string<Primitive>|Primitive $primitive
     * @param array<string, mixed> $currentArgs
     */
    public function completion(
        Primitive|string $primitive,
        string $argumentName,
        string $argumentValue = '',
        array $currentArgs = []
    ): TestResponse {
        $primitive = $this->resolvePrimitive($primitive);
        $server = $this->initializeServer();

        $request = new JsonRpcRequest(
            uniqid(),
            'completion/complete',
            [
                'ref' => $this->buildCompletionRef($primitive),
                'argument' => [
                    'name' => $argumentName,
                    'value' => $argumentValue,
                ],
                'context' => [
                    'arguments' => $currentArgs,
                ],
            ],
        );

        $response = $this->executeRequest($server, $request);

        return new TestResponse($primitive, $response);
    }

    /**
     * Build the completion reference for a primitive.
     *
     * @return array<string, mixed>
     */
    protected function buildCompletionRef(Primitive $primitive): array
    {
        return match (true) {
            $primitive instanceof Prompt => [
                'type' => 'ref/prompt',
                'name' => $primitive->name(),
            ],
            $primitive instanceof McpResource => [
                'type' => 'ref/resource',
                'uri' => $primitive->uri(),
            ],
            default => throw new InvalidArgumentException('Unsupported primitive type for completion.'),
        };
    }

    /**
     * Resolve a primitive class or use the supplied instance.
     *
     * @param class-string<Primitive>|Primitive $primitive
     */
    protected function resolvePrimitive(Primitive|string $primitive): Primitive
    {
        return is_string($primitive)
            ? Container::getInstance()->make($primitive)
            : $primitive;
    }

    /**
     * Start the server with a testing transport.
     */
    protected function initializeServer(): Server
    {
        $server = Container::getInstance()->make(
            $this->serverClass,
            ['transport' => new FakeTransporter]
        );

        $server->start();

        return $server;
    }

    /**
     * Execute a request and capture protocol errors.
     */
    protected function executeRequest(Server $server, JsonRpcRequest $request): mixed
    {
        try {
            return (fn (): iterable|JsonRpcResponse => $this->runMethodHandle($request, $this->createContext()))->call($server);
        } catch (JsonRpcException $jsonRpcException) {
            return $jsonRpcException->toJsonRpcResponse();
        }
    }

    /**
     * Authenticate the current invocation as the given user.
     */
    public function actingAs(Authenticatable $user, ?string $guard = null): static
    {
        if (property_exists($user, 'wasRecentlyCreated')) {
            $user->wasRecentlyCreated = false;
        }

        $this->app->make('auth')->guard($guard)->setUser($user);

        $this->app->make('auth')->shouldUse($guard);

        return $this;
    }

    /**
     * Invoke a primitive through the server's method handler.
     *
     * @param class-string<Primitive>|Primitive $primitive
     * @param array<string, mixed> $arguments
     *
     * @throws JsonRpcException
     */
    protected function run(string $method, Primitive|string $primitive, array $arguments = []): TestResponse
    {
        $primitive = $this->resolvePrimitive($primitive);
        $server = $this->initializeServer();

        $params = [
            ...$primitive->toMethodCall(),
            'arguments' => $arguments,
        ];

        if ($method === 'resources/read' && $primitive instanceof HasUriTemplate) {
            $params['uri'] = $this->expandUriTemplate($primitive->uriTemplate(), $arguments);
        }

        $request = new JsonRpcRequest(uniqid(), $method, $params);

        $response = $this->executeRequest($server, $request);

        return new TestResponse($primitive, $response, is_string($params['uri'] ?? null) ? $params['uri'] : null);
    }

    /**
     * Expand a resource URI using the supplied variables.
     *
     * @param array<string, mixed> $variables
     */
    protected function expandUriTemplate(UriTemplate $template, array $variables): string
    {
        $expanded = (string) $template;

        foreach ($template->variableNames() as $name) {
            if (! array_key_exists($name, $variables)) {
                throw new InvalidArgumentException("Missing value for URI template variable [{$name}].");
            }

            $value = $variables[$name];

            if (! is_scalar($value) && ! $value instanceof Stringable) {
                throw new InvalidArgumentException("URI template variable [{$name}] must be a scalar or Stringable value.");
            }

            $value = (string) $value;

            if (str_contains($value, '/')) {
                throw new InvalidArgumentException("URI template variable [{$name}] value must not contain '/'.");
            }

            $expanded = str_replace('{' . $name . '}', $value, $expanded);
        }

        return $expanded;
    }
}
