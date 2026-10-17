<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Hypervel\Container\Container;
use Hypervel\Mcp\Client\ClientManager;
use Hypervel\Mcp\Client\Contracts\Transport;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\Methods\Ping;
use Hypervel\Mcp\Client\Methods\Prompts\GetPrompt;
use Hypervel\Mcp\Client\Methods\Prompts\ListPrompts;
use Hypervel\Mcp\Client\Methods\Resources\ListResources;
use Hypervel\Mcp\Client\Methods\Resources\ReadResource;
use Hypervel\Mcp\Client\Methods\Tools\CallTool;
use Hypervel\Mcp\Client\Methods\Tools\ListTools;
use Hypervel\Mcp\Client\Primitives\Prompt;
use Hypervel\Mcp\Client\Primitives\Resource as McpResource;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Client\Protocol;
use Hypervel\Mcp\Client\ResponseCache;
use Hypervel\Mcp\Client\Schema\DiscoverResult;
use Hypervel\Mcp\Client\Schema\InitializeResult;
use Hypervel\Mcp\Client\Schema\PromptResult;
use Hypervel\Mcp\Client\Schema\ResourceReadResult;
use Hypervel\Mcp\Client\Schema\ToolResult;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Client\Transport\StdioTransport;
use Hypervel\Mcp\Client\Transport\TransportFactory;
use Hypervel\Mcp\Enums\ErrorCode;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Mcp\Support\MirroredParameters;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class Client
{
    protected Protocol $protocol;

    protected ?string $name = null;

    /** @var null|array<int, string> */
    protected ?array $onlyTools = null;

    /** @var array<int, string> */
    protected array $exceptTools = [];

    /**
     * Create a client for the supplied transport.
     */
    public function __construct(
        protected Transport $transport,
        public ?Implementation $clientInfo = null,
    ) {
        $this->clientInfo = $clientInfo ?? $this->defaultClientInfo();

        $this->protocol = new Protocol($this->transport, $this->clientInfo);
    }

    /**
     * Get the application's default client information.
     */
    protected function defaultClientInfo(): Implementation
    {
        return new Implementation(
            name: config()->string('app.name'),
            version: '0.0.1',
        );
    }

    /**
     * Set the client's registered name.
     */
    public function setName(?string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Create a local subprocess client.
     *
     * @param array<int, string> $args
     * @param array<string, string> $env
     */
    public static function local(string $command, array $args = [], array $env = []): static
    {
        return new static(new StdioTransport($command, $args, $env));
    }

    /**
     * Create an HTTP client.
     */
    public static function web(string $url): WebClient
    {
        return new WebClient(new HttpTransport($url));
    }

    /**
     * Cache eligible responses in the given store and scope.
     */
    public function withCache(?string $store = null, ?string $for = null): static
    {
        $this->protocol->useCache(new ResponseCache($store, $for));

        return $this;
    }

    /**
     * Disable response caching.
     */
    public function withoutCache(): static
    {
        $this->protocol->useCache(null);

        return $this;
    }

    /**
     * Set the transport timeout.
     */
    public function withTimeout(float $seconds): static
    {
        $this->transport->setTimeoutSeconds($seconds);

        return $this;
    }

    /**
     * Limit calls to the given tool patterns.
     *
     * @param array<int, string> $tools
     */
    public function onlyTools(array $tools): static
    {
        $this->onlyTools = $tools;

        return $this;
    }

    /**
     * Exclude the given tool patterns.
     *
     * @param array<int, string> $tools
     */
    public function exceptTools(array $tools): static
    {
        $this->exceptTools = $tools;

        return $this;
    }

    /**
     * Determine whether the tool is allowed on this client.
     */
    public function allowsTool(string $name): bool
    {
        return ($this->onlyTools === null || Str::is($this->onlyTools, $name))
            && ! Str::is($this->exceptTools, $name);
    }

    /**
     * Connect and negotiate the server protocol.
     */
    public function connect(): static
    {
        $this->protocol->connect();

        return $this;
    }

    /**
     * Disconnect the client and release its transport.
     */
    public function disconnect(): void
    {
        $this->protocol->disconnect();
    }

    /**
     * Determine whether the client is connected.
     */
    public function connected(): bool
    {
        return $this->protocol->connected();
    }

    /**
     * Get the legacy initialization result.
     */
    public function initializeResult(): ?InitializeResult
    {
        return $this->protocol->initializeResult();
    }

    /**
     * Get the discovery result.
     */
    public function discoverResult(): ?DiscoverResult
    {
        return $this->protocol->discoverResult();
    }

    /**
     * Get the server's negotiated capabilities.
     *
     * @return array<string, mixed>
     */
    public function capabilities(): array
    {
        $this->protocol->connect();

        return $this->protocol->capabilities();
    }

    /**
     * Get the server's implementation information.
     */
    public function serverInfo(): ?Implementation
    {
        $this->protocol->connect();

        return $this->protocol->serverInfo();
    }

    /**
     * Get the server's instructions.
     */
    public function instructions(): ?string
    {
        $this->protocol->connect();

        return $this->protocol->instructions();
    }

    /**
     * Pin the client to a supported protocol version.
     */
    public function withProtocolVersion(?ProtocolVersion $version): static
    {
        if ($version instanceof ProtocolVersion && ! in_array($version->value, ProtocolVersion::clientSupported(), true)) {
            throw new ClientException(sprintf(
                'This client does not support protocol version [%s]. It supports [%s].',
                $version->value,
                implode(', ', ProtocolVersion::clientSupported()),
            ));
        }

        $this->protocol->pinProtocolVersion($version);

        return $this;
    }

    /**
     * Get the negotiated protocol version.
     */
    public function protocolVersion(): ProtocolVersion
    {
        $this->protocol->connect();

        return $this->protocol->connectionProtocol();
    }

    /**
     * Ping the server.
     */
    public function ping(): void
    {
        (new Ping)->handle($this->protocol);
    }

    /**
     * List the available tools allowed on this client.
     *
     * @param null|iterable<string, Tool> $default
     * @return Collection<string, Tool>
     */
    public function tools(?int $limit = null, ?iterable $default = null): Collection
    {
        try {
            return (new ListTools(client: $this, limit: $limit))->handle($this->protocol)
                ->filter(fn (Tool $tool): bool => $this->allowsTool($tool->name));
        } catch (AuthorizationRequiredException $authorizationRequiredException) {
            if ($default === null) {
                throw $authorizationRequiredException;
            }

            return Collection::make($default);
        }
    }

    /**
     * Call a tool with the given arguments.
     *
     * @param array<string, mixed> $arguments
     */
    public function callTool(Tool|string $tool, array $arguments = []): ToolResult
    {
        $name = $tool instanceof Tool ? $tool->name : $tool;
        $mirroredParameters = $tool instanceof Tool ? $tool->mirroredParameters() : null;

        if (! $this->allowsTool($name)) {
            throw new ClientException("MCP tool [{$name}] is not allowed on this client.");
        }

        try {
            return (new CallTool($name, $arguments, $mirroredParameters))->handle($this->protocol);
        } catch (JsonRpcException $jsonRpcException) {
            if ($jsonRpcException->getCode() !== ErrorCode::HEADER_MISMATCH->value) {
                throw $jsonRpcException;
            }

            $refreshed = $this->tools()->get($name)?->mirroredParameters();

            if (! $refreshed instanceof MirroredParameters
                || $refreshed->headers($arguments) === ($mirroredParameters?->headers($arguments) ?? [])) {
                throw $jsonRpcException;
            }

            return (new CallTool($name, $arguments, $refreshed))->handle($this->protocol);
        }
    }

    /**
     * List the server's prompts.
     *
     * @param null|iterable<string, Prompt> $default
     * @return Collection<string, Prompt>
     */
    public function prompts(?int $limit = null, ?iterable $default = null): Collection
    {
        try {
            return (new ListPrompts(limit: $limit))->handle($this->protocol);
        } catch (AuthorizationRequiredException $authorizationRequiredException) {
            if ($default === null) {
                throw $authorizationRequiredException;
            }

            return Collection::make($default);
        }
    }

    /**
     * Get a prompt with the given arguments.
     *
     * @param array<string, mixed> $arguments
     */
    public function getPrompt(string $name, array $arguments = []): PromptResult
    {
        return (new GetPrompt($name, $arguments))->handle($this->protocol);
    }

    /**
     * List the server's resources.
     *
     * @param null|iterable<string, McpResource> $default
     * @return Collection<string, McpResource>
     */
    public function resources(?int $limit = null, ?iterable $default = null): Collection
    {
        try {
            return (new ListResources(limit: $limit))->handle($this->protocol);
        } catch (AuthorizationRequiredException $authorizationRequiredException) {
            if ($default === null) {
                throw $authorizationRequiredException;
            }

            return Collection::make($default);
        }
    }

    /**
     * Read the resource at the given URI.
     */
    public function readResource(string $uri): ResourceReadResult
    {
        return (new ReadResource($uri))->handle($this->protocol);
    }

    /**
     * Serialize the client's name or connection recipe.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        if ($this->name !== null) {
            return ['name' => $this->name];
        }

        return [
            'name' => null,
            'clientInfo' => $this->clientInfo,
            'transport' => $this->transport->recipe(),
            'protocolVersion' => $this->protocol->pinnedProtocolVersion()?->value,
            'cache' => $this->protocol->cache(),
            'onlyTools' => $this->onlyTools,
            'exceptTools' => $this->exceptTools,
        ];
    }

    /**
     * Restore the client without restoring a live connection.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->name = Arr::get($data, 'name');

        if ($this->name !== null) {
            $resolved = Container::getInstance()->make(ClientManager::class)->build($this->name);

            $this->restoreNamedClient($resolved);
            $pinned = $resolved->protocol->pinnedProtocolVersion();
            $cache = $resolved->protocol->cache();
        } else {
            $this->clientInfo = Arr::get($data, 'clientInfo');
            $this->transport = TransportFactory::fromRecipe(Arr::get($data, 'transport'));
            $pinned = ProtocolVersion::tryFrom((string) Arr::get($data, 'protocolVersion'));
            $cache = Arr::get($data, 'cache');
            $this->onlyTools = Arr::get($data, 'onlyTools');
            $this->exceptTools = Arr::get($data, 'exceptTools', []);
        }

        $this->clientInfo ??= $this->defaultClientInfo();

        $this->protocol = new Protocol($this->transport, $this->clientInfo, $pinned);
        $this->protocol->useCache($cache instanceof ResponseCache ? $cache : null);
    }

    /**
     * Restore configuration from the registered factory without reusing its connection.
     */
    protected function restoreNamedClient(Client $client): void
    {
        $this->transport = $client->transport;
        $this->clientInfo = $client->clientInfo;
        $this->onlyTools = $client->onlyTools;
        $this->exceptTools = $client->exceptTools;
    }

    /**
     * Disconnect a remaining live connection.
     */
    public function __destruct()
    {
        if ($this->connected()) {
            $this->disconnect();
        }
    }
}
