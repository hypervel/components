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
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Traits\Macroable;
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
     * On the shared manager, factories persist across requests. Re-registration
     * disconnects only the current owner's client; other owners retain theirs
     * until cleanup.
     *
     * @param Closure(): Client $factory
     */
    #[BootOnly(whenShared: true)]
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
            config()->nullableArray("mcp.clients.{$name}", null) !== null => $this->fromConfig($name),
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

        if (filled($config->nullableString("{$key}.url", null))) {
            $client = $this->webClientFromConfig($key);
        } elseif (filled($command = $config->nullableString("{$key}.command", null))) {
            $client = Client::local(
                $command,
                $config->array("{$key}.args", []),
                array_filter($config->array("{$key}.env", []), filled(...)),
            );
        } else {
            throw new ClientException("MCP client [{$name}] must define a [url] or [command].");
        }

        // @phpstan-ignore hypervel.untypedConfigRead (Clients accept numeric timeouts including fractional seconds.)
        if (is_numeric($timeout = $config->get("{$key}.timeout"))) {
            $client->withTimeout((float) $timeout);
        }

        // @phpstan-ignore hypervel.untypedConfigRead (Caching can be enabled with a boolean or a record of cache options.)
        if ($cache = $config->get("{$key}.cache")) {
            $client->withCache(...is_array($cache) ? Arr::only($cache, ['store', 'for']) : []);
        }

        if (filled($version = $config->nullableString("{$key}.protocol_version", null))) {
            $client->withProtocolVersion(ProtocolVersion::from($version));
        }

        if (($only = $config->nullableArray("{$key}.tools.only", null)) !== null) {
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

        if (filled($token = $config->nullableString("{$key}.token", null))) {
            $client->withToken($token);
        }

        if ($config->nullableArray("{$key}.oauth", null) !== null) {
            $client->withOAuth(
                clientId: $config->nullableString("{$key}.oauth.client_id", null),
                clientSecret: $config->nullableString("{$key}.oauth.client_secret", null),
                scope: $config->nullableString("{$key}.oauth.scope", null),
                redirectUri: $config->nullableString("{$key}.oauth.redirect_uri", null),
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
                $exception = Coroutine::preferCancellation($exception, $throwable);
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
        } catch (ClientException $exception) {
            if (Coroutine::causedByCancellation($exception)) {
                throw $exception;
            }
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
