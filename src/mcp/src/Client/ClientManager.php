<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Swoole\Coroutine\CanceledException;
use Throwable;

class ClientManager
{
    use Macroable;

    protected const string CLIENTS_CONTEXT_KEY_PREFIX = '__mcp.clients.';

    /** @var array<string, Closure(): Client> */
    protected array $factories = [];

    /**
     * Keep the protected property while its clients belong to the current owner.
     *
     * @var array<string, Client>
     */
    protected array $clients {
        &get {
            return $this->state()->clients;
        }
        set {
            $this->state()->clients = $value;
        }
    }

    private ?ClientManagerState $nonCoroutineState = null;

    /**
     * Register a named client factory.
     *
     * Boot-only. Factories persist across requests. Re-registration disconnects
     * only the current owner's client; other owners retain theirs until cleanup.
     *
     * @param Closure(): Client $factory
     */
    public function registerClient(string $name, Closure $factory): void
    {
        if (isset($this->clients[$name])) {
            $this->disconnect($this->clients[$name]);

            unset($this->clients[$name]);
        }

        $this->factories[$name] = $factory;
    }

    /**
     * Get a named client for the current owner.
     */
    public function client(string $name): Client
    {
        return $this->clients[$name] ??= $this->build($name);
    }

    /**
     * Build a fresh named client from its factory or configuration.
     */
    public function build(string $name): Client
    {
        $client = match (true) {
            isset($this->factories[$name]) => ($this->factories[$name])(),
            is_array(config("mcp.clients.{$name}")) => $this->fromConfig($name),
            default => throw new ClientException("MCP client [{$name}] has not been registered."),
        };

        return $client->setName($name);
    }

    /**
     * Build a client from its named configuration.
     */
    protected function fromConfig(string $name): Client
    {
        $config = config();
        $key = "mcp.clients.{$name}";

        if (filled($config->get("{$key}.url"))) {
            $client = $this->webClientFromConfig($key);
        } elseif (filled($config->get("{$key}.command"))) {
            $client = Client::local(
                $config->string("{$key}.command"),
                $config->array("{$key}.args", []),
                array_filter($config->array("{$key}.env", []), filled(...)),
            );
        } else {
            throw new ClientException("MCP client [{$name}] must define a [url] or [command].");
        }

        if (is_numeric($timeout = $config->get("{$key}.timeout"))) {
            $client->withTimeout((float) $timeout);
        }

        if ($cache = $config->get("{$key}.cache")) {
            $client->withCache(...is_array($cache) ? Arr::only($cache, ['store', 'for']) : []);
        }

        if (filled($version = $config->get("{$key}.protocol_version"))) {
            $client->withProtocolVersion(ProtocolVersion::from($version));
        }

        if (is_array($only = $config->get("{$key}.tools.only"))) {
            $client->onlyTools($only);
        }

        return $client->exceptTools($config->array("{$key}.tools.except", []));
    }

    /**
     * Build an HTTP client from its named configuration.
     */
    protected function webClientFromConfig(string $key): WebClient
    {
        $config = config();

        $client = Client::web($config->string("{$key}.url"))
            ->withHeaders(array_filter($config->array("{$key}.headers", []), filled(...)));

        if (filled($config->get("{$key}.token"))) {
            $client->withToken($config->string("{$key}.token"));
        }

        if (is_array($config->get("{$key}.oauth"))) {
            $client->withOAuth(
                clientId: $config->get("{$key}.oauth.client_id"),
                clientSecret: $config->get("{$key}.oauth.client_secret"),
                scope: $config->get("{$key}.oauth.scope"),
                redirectUri: $config->get("{$key}.oauth.redirect_uri"),
            );
        }

        return $client;
    }

    /**
     * Disconnect every client owned by the current invocation.
     */
    public function disconnectAll(): void
    {
        $state = $this->currentState();

        if ($state === null) {
            return;
        }

        $clients = $state->clients;
        $state->clients = [];
        $exception = null;

        foreach ($clients as $client) {
            try {
                $this->disconnect($client);
            } catch (Throwable $throwable) {
                if ($exception === null
                    || ($throwable instanceof CanceledException && ! $exception instanceof CanceledException)) {
                    $exception = $throwable;
                }
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Disconnect a client while tolerating protocol teardown errors.
     */
    protected function disconnect(Client $client): void
    {
        try {
            $client->disconnect();
        } catch (ClientException) {
        }
    }

    /**
     * Get existing owner state without allocating clients or exit cleanup.
     */
    private function currentState(): ?ClientManagerState
    {
        return Coroutine::inCoroutine()
            ? CoroutineContext::get(self::CLIENTS_CONTEXT_KEY_PREFIX . spl_object_id($this))
            : $this->nonCoroutineState;
    }

    /**
     * Get the current owner's clients and register their exit cleanup once.
     */
    private function state(): ClientManagerState
    {
        if (($state = $this->currentState()) !== null) {
            return $state;
        }

        $state = new ClientManagerState;

        if (Coroutine::inCoroutine()) {
            CoroutineContext::set(self::CLIENTS_CONTEXT_KEY_PREFIX . spl_object_id($this), $state);
            Coroutine::defer($this->disconnectAll(...));
        } else {
            $this->nonCoroutineState = $state;
        }

        return $state;
    }
}
