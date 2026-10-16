<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Generator;
use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Enums\Extension;
use Hypervel\Mcp\Enums\MetaKey;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Server\AppResource;
use Hypervel\Mcp\Server\Attributes\Cacheable;
use Hypervel\Mcp\Server\Attributes\Instructions;
use Hypervel\Mcp\Server\Attributes\Name;
use Hypervel\Mcp\Server\Attributes\Version;
use Hypervel\Mcp\Server\Concerns\HasIcons;
use Hypervel\Mcp\Server\Contracts\Method;
use Hypervel\Mcp\Server\Contracts\StreamsMessages;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Methods\CallTool;
use Hypervel\Mcp\Server\Methods\CompletionComplete;
use Hypervel\Mcp\Server\Methods\Concerns\ResolvesResources;
use Hypervel\Mcp\Server\Methods\Discover;
use Hypervel\Mcp\Server\Methods\GetPrompt;
use Hypervel\Mcp\Server\Methods\Initialize;
use Hypervel\Mcp\Server\Methods\Listen;
use Hypervel\Mcp\Server\Methods\ListPrompts;
use Hypervel\Mcp\Server\Methods\ListResources;
use Hypervel\Mcp\Server\Methods\ListResourceTemplates;
use Hypervel\Mcp\Server\Methods\ListTools;
use Hypervel\Mcp\Server\Methods\Ping;
use Hypervel\Mcp\Server\Methods\ReadResource;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\RequestContext;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Mcp\Server\ServerContext;
use Hypervel\Mcp\Server\Testing\PendingTestResponse;
use Hypervel\Mcp\Server\Testing\TestListResponse;
use Hypervel\Mcp\Server\Testing\TestResponse;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Transport\JsonRpcNotification;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Support\Arr;
use InvalidArgumentException;
use JsonException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * @mixin PendingTestResponse
 */
abstract class Server
{
    use HasIcons;
    use ResolvesResources;

    public const string CAPABILITY_TOOLS = 'tools';

    public const string CAPABILITY_RESOURCES = 'resources';

    public const string CAPABILITY_PROMPTS = 'prompts';

    public const string CAPABILITY_COMPLETIONS = 'completions';

    public const array CACHEABLE_METHODS = [
        'server/discover',
        'tools/list',
        'prompts/list',
        'resources/list',
        'resources/templates/list',
        'resources/read',
    ];

    protected string $name = 'Hypervel MCP Server';

    protected string $version = '0.0.1';

    protected string $instructions = <<<'MARKDOWN'
        This MCP server lets AI agents interact with our Hypervel application.
    MARKDOWN;

    /**
     * @var array<int, string>
     */
    protected array $supportedProtocolVersion = [];

    /**
     * @var array<int, Extension>
     */
    protected array $extensions = [];

    /**
     * @var array<string, array<string, bool>|stdClass|string>
     */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => [
            'listChanged' => false,
        ],
        self::CAPABILITY_RESOURCES => [
            'listChanged' => false,
        ],
        self::CAPABILITY_PROMPTS => [
            'listChanged' => false,
        ],
    ];

    /**
     * @var array<int|string, array<int, class-string<Tool>|Tool>|class-string<Tool>|Tool>
     */
    protected array $tools = [];

    /**
     * @var array<int, class-string<McpResource>|McpResource>
     */
    protected array $resources = [];

    /**
     * @var array<int, class-string<Prompt>|Prompt>
     */
    protected array $prompts = [];

    public int $maxPaginationLength = 50;

    public int $defaultPaginationLength = 15;

    /**
     * @var array<string, class-string<Method>>
     */
    protected array $methods = [
        'tools/list' => ListTools::class,
        'tools/call' => CallTool::class,
        'resources/list' => ListResources::class,
        'resources/read' => ReadResource::class,
        'resources/templates/list' => ListResourceTemplates::class,
        'prompts/list' => ListPrompts::class,
        'prompts/get' => GetPrompt::class,
        'completion/complete' => CompletionComplete::class,
        'server/discover' => Discover::class,
        'initialize' => Initialize::class,
        'ping' => Ping::class,
        'subscriptions/listen' => Listen::class,
    ];

    /**
     * Create a server for the given transport.
     */
    public function __construct(
        protected Transport $transport,
    ) {
    }

    /**
     * Add or modify a server capability.
     *
     * Using dot notation like "feature.enabled" will create a nested capability array.
     * Passing a single key like "anotherFeature" will register an empty object capability.
     */
    public function addCapability(string $key, bool $value = true): void
    {
        if (str_contains($key, '.')) {
            [$root, $child] = explode('.', $key, 2);
            $existing = $this->capabilities[$root] ?? [];

            if (! is_array($existing)) {
                $existing = [];
            }

            $existing[$child] = $value;
            $this->capabilities[$root] = $existing;

            return;
        }

        // Represent empty capability as an object when JSON encoded
        $this->capabilities[$key] = (object) [];
    }

    /**
     * Register a custom JSON-RPC method handler.
     *
     * @param class-string<Method> $handler
     */
    public function addMethod(string $method, string $handler): void
    {
        $this->methods[$method] = $handler;
    }

    /**
     * Boot the server and register its incoming message handler.
     */
    public function start(): void
    {
        $this->boot();
        $this->detectUiCapability();

        $this->transport->onReceive($this->handle(...));
    }

    /**
     * Bootstrap the server.
     */
    protected function boot(): void
    {
    }

    /**
     * Handle an incoming JSON-RPC message.
     */
    public function handle(string $rawMessage): void
    {
        $context = $this->createContext();
        $requestId = null;

        try {
            $jsonRequest = json_decode($rawMessage, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new JsonRpcException('Parse error: Invalid JSON was received by the server.', ErrorCode::PARSE_ERROR->value);
            }

            if (! is_array($jsonRequest) || array_is_list($jsonRequest)) {
                throw new JsonRpcException('Invalid Request: The message must be an object.', ErrorCode::INVALID_REQUEST->value);
            }

            $request = array_key_exists('id', $jsonRequest)
                ? JsonRpcRequest::from($jsonRequest)
                : JsonRpcNotification::from($jsonRequest);

            if ($request instanceof JsonRpcNotification) {
                return;
            }

            $requestId = $request->id;
            $request->source = $rawMessage;

            if (! $request->isLegacy()) {
                $this->validateProtocolMeta($request, $context);
            }

            if (! isset($this->methods[$request->method])) {
                throw new JsonRpcException(
                    "The method [{$request->method}] was not found.",
                    ErrorCode::METHOD_NOT_FOUND->value,
                    $request->id,
                );
            }

            $this->handleMessage($request, $context);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->sendException($exception, $context, $requestId);
        }
    }

    /**
     * Send a terminal failure from eager or streamed request handling.
     */
    protected function sendException(Throwable $exception, ServerContext $context, int|string|null $requestId): void
    {
        if ($exception instanceof JsonRpcException) {
            $response = $exception->toJsonRpcResponse();
        } else {
            report($exception);

            if (Container::getInstance()->make(Repository::class)->boolean('app.debug')) {
                throw $exception;
            }

            $response = JsonRpcResponse::error(
                $requestId,
                ErrorCode::INTERNAL_ERROR->value,
                'Something went wrong while processing the request.',
            );
        }

        try {
            $this->send($response, $context);
        } catch (JsonException $exception) {
            report($exception);

            // Error data supplied by application code can also be unencodable.
            $this->transport->send(JsonRpcResponse::error(
                $requestId,
                ErrorCode::INTERNAL_ERROR->value,
                'Something went wrong while processing the request.',
            )->toJson());
        }
    }

    /**
     * Create the context for one incoming message.
     */
    public function createContext(): ServerContext
    {
        $name = $this->resolveAttribute(Name::class);
        $version = $this->resolveAttribute(Version::class);
        $instructions = $this->resolveAttribute(Instructions::class);

        return new ServerContext(
            supportedProtocolVersions: $this->supportedProtocolVersion ?: ProtocolVersion::serverSupported(),
            serverCapabilities: $this->resolvedCapabilities(),
            implementation: new Implementation(
                name: $name->value ?? $this->name,
                version: $version->value ?? $this->version,
                icons: $this->resolvedIcons(),
            ),
            instructions: $instructions->value ?? $this->instructions,
            maxPaginationLength: $this->maxPaginationLength,
            defaultPaginationLength: $this->defaultPaginationLength,
            tools: $this->tools,
            resources: $this->resources,
            prompts: $this->prompts,
        );
    }

    /**
     * Return the server's icons.
     *
     * @return list<Icon>
     */
    protected function icons(): array
    {
        return [];
    }

    /**
     * Validate the protocol metadata on a modern request.
     *
     * @throws JsonRpcException
     */
    protected function validateProtocolMeta(JsonRpcRequest $request, ServerContext $context): void
    {
        $meta = $request->meta() ?? [];

        $expected = [
            MetaKey::PROTOCOL_VERSION->value => 'is_string',
            MetaKey::CLIENT_CAPABILITIES->value => fn (mixed $value): bool => is_array($value) && ($value === [] || ! array_is_list($value)),
        ];

        foreach ($expected as $metaKey => $isValid) {
            if (! array_key_exists($metaKey, $meta) || ! $isValid($meta[$metaKey])) {
                throw new JsonRpcException(
                    "Invalid params: The request [_meta] is missing the required [{$metaKey}] member.",
                    ErrorCode::INVALID_PARAMS->value,
                    $request->id,
                );
            }
        }

        $requestedVersion = $meta[MetaKey::PROTOCOL_VERSION->value];

        if (! in_array($requestedVersion, $context->supportedProtocolVersions, true)) {
            throw new JsonRpcException(
                'Unsupported protocol version',
                ErrorCode::UNSUPPORTED_PROTOCOL_VERSION->value,
                $request->id,
                [
                    'supported' => $context->supportedProtocolVersions,
                    'requested' => $requestedVersion,
                ],
            );
        }
    }

    /**
     * Invoke the method and deliver its eager or streamed response.
     *
     * @throws JsonRpcException
     */
    protected function handleMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $response = $this->runMethodHandle($request, $context);

        if (! is_iterable($response)) {
            $this->send($response, $context, $request);

            return;
        }

        $producer = fn (): Generator => $this->streamResponses($response, $request, $context);

        if ($this->transport instanceof StreamsMessages) {
            $this->transport->streamMessages($producer, ! $request->isLegacy());

            return;
        }

        $this->transport->stream(static function () use ($producer): void {
            foreach ($producer() as $message) {
                // Custom transports keep receiving messages through send().
            }
        });
    }

    /**
     * Produce responses through the existing send extension point.
     *
     * @param iterable<JsonRpcResponse> $response
     * @return Generator<int, null>
     */
    protected function streamResponses(iterable $response, JsonRpcRequest $request, ServerContext $context): Generator
    {
        try {
            foreach ($response as $message) {
                $this->send($message, $context, $request);
                yield;
            }
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->sendException($exception, $context, $request->id);
            yield;
        }
    }

    /**
     * Add result metadata and send the response through the transport.
     */
    protected function send(JsonRpcResponse $response, ServerContext $context, ?JsonRpcRequest $request = null): void
    {
        if ($request instanceof JsonRpcRequest && array_key_exists('result', $response->content)) {
            $result = (array) $response->content['result'];
            $result['_meta'][MetaKey::SERVER_INFO->value] = $context->implementation->toArray();

            $response->content['result'] = [
                'resultType' => 'complete',
                ...$this->resolveCacheHints($request, $context),
                ...$result,
            ];
        }

        $this->transport->send($response->toJson());
    }

    /**
     * Return method-specific cache hints.
     *
     * @return array<string, Cacheable>
     */
    protected function cacheHints(): array
    {
        return [];
    }

    /**
     * Resolve the cache hints for a successful result.
     *
     * @return array<string, int|string>
     */
    protected function resolveCacheHints(JsonRpcRequest $request, ServerContext $context): array
    {
        if (! in_array($request->method, self::CACHEABLE_METHODS, true)) {
            return [];
        }

        if (isset($request->params['inputResponses']) || isset($request->params['requestState'])) {
            return [];
        }

        $cacheable = $this->resourceCacheable($request, $context)
            ?? $this->cacheHints()[$request->method]
            ?? $this->resolveAttribute(Cacheable::class)
            ?? new Cacheable;

        return $cacheable->toArray();
    }

    /**
     * Resolve cache hints from the resource selected for this message.
     */
    protected function resourceCacheable(JsonRpcRequest $request, ServerContext $context): ?Cacheable
    {
        if ($request->method !== 'resources/read') {
            return null;
        }

        if ($context->resolvedResource !== null && $context->resolvedResourceRequest?->uri() === $request->get('uri')) {
            return RequestContext::run($context->resolvedResourceRequest, fn (): ?Cacheable => $context->resolvedResource->cacheable());
        }

        try {
            return $this->resolveResource($request->get('uri'), $context)->cacheable();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Invoke a method under its request context.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     *
     * @throws JsonRpcException
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        $container = Container::getInstance();

        /** @var Method $methodClass */
        $methodClass = $container->make(
            $this->methods[$request->method],
        );

        return RequestContext::run($request->toRequest(), fn (): iterable|JsonRpcResponse => $methodClass->handle($request, $context));
    }

    /**
     * Resolve the advertised server capabilities and extensions.
     *
     * @return array<string, mixed>
     */
    protected function resolvedCapabilities(): array
    {
        $extensions = Arr::mapWithKeys(
            $this->extensions,
            fn (Extension $extension): array => [$extension->value => (object) []],
        );

        return $extensions === []
            ? $this->capabilities
            : [...$this->capabilities, 'extensions' => $extensions];
    }

    /**
     * Enable the UI extension when an App resource is registered.
     */
    protected function detectUiCapability(): void
    {
        if (collect($this->resources)->contains(fn (McpResource|string $resource): bool => is_subclass_of($resource, AppResource::class))) {
            $this->extensions[] = Extension::Ui;
        }
    }

    /**
     * Start a server test invocation.
     *
     * @param array<array-key, mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): PendingTestResponse|TestResponse|TestListResponse
    {
        $pendingTestResponse = new PendingTestResponse(
            Container::getInstance(),
            static::class,
        );

        return $pendingTestResponse->{$name}(...$arguments);
    }
}
