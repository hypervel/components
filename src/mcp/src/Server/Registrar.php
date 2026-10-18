<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\ClientManager;
use Hypervel\Mcp\Client\OAuth\OAuthRouteRegistrar;
use Hypervel\Mcp\Client\OAuth\TokenSet;
use Hypervel\Mcp\Server;
use Hypervel\Mcp\Server\Contracts\Transport;
use Hypervel\Mcp\Server\Http\Controllers\OAuthRegisterController;
use Hypervel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;
use Hypervel\Mcp\Server\Middleware\ReorderJsonAccept;
use Hypervel\Mcp\Server\Middleware\ValidateMcpHeaders;
use Hypervel\Mcp\Server\Middleware\ValidateOrigin;
use Hypervel\Mcp\Server\Transport\HttpTransport;
use Hypervel\Mcp\Server\Transport\StdioTransport;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Route as Router;
use Hypervel\Support\Traits\Macroable;
use LogicException;

class Registrar
{
    use Macroable;

    public const string OAUTH_SCOPE = 'mcp:use';

    /** @var array<string, callable> */
    protected array $localServers = [];

    /** @var array<string, Route> */
    protected array $httpServers = [];

    /**
     * Register an HTTP server.
     *
     * Boot-only. Routes and server registrations persist for the worker lifetime.
     *
     * @param class-string<Server> $serverClass
     */
    public function web(string $route, string $serverClass): Route
    {
        // https://modelcontextprotocol.io/specification/2025-11-25/basic/transports#listening-for-messages-from-the-server
        Router::get($route, fn (): Response => response('', 405)->header('Allow', 'POST'));

        Router::delete($route, fn (): Response => response('', 405)->header('Allow', 'POST'));

        $route = Router::post($route, static fn (): mixed => static::startServer(
            $serverClass,
            static fn (): HttpTransport => new HttpTransport(request()),
        ))->middleware([
            ValidateOrigin::class,
            ReorderJsonAccept::class,
            ValidateMcpHeaders::class,
            AddWwwAuthenticateHeader::class,
        ]);

        $this->httpServers[$route->uri()] = $route;

        return $route;
    }

    /**
     * Register a local server.
     *
     * Boot-only. Server factories persist for the worker lifetime.
     *
     * @param class-string<Server> $serverClass
     */
    public function local(string $handle, string $serverClass): void
    {
        $this->localServers[$handle] = fn (): mixed => static::startServer($serverClass, fn (): StdioTransport => new StdioTransport);
    }

    /**
     * Register a named client factory.
     *
     * Boot-only. Client factories persist for the worker lifetime.
     *
     * @param Closure(): Client $factory
     */
    public function registerClient(string $name, Closure $factory): void
    {
        $this->clientManager()->registerClient($name, $factory);
    }

    /**
     * Get a named client for the current owner.
     */
    public function client(string $name): Client
    {
        return $this->clientManager()->client($name);
    }

    /**
     * Register OAuth routes for a named client.
     *
     * Boot-only. The routes persist for the worker lifetime.
     *
     * @param array{0: class-string, 1: string}|Closure(string, TokenSet): mixed $handler receives provider or client, token, and optional returnTo by parameter name
     * @param array<int, string>|string $middleware
     * @param array<string, mixed> $clientMetadata
     */
    public function oAuthRoutesFor(
        string $client,
        Closure|array $handler,
        array|string $middleware = 'web',
        ?string $connectUri = null,
        ?string $callbackUri = null,
        ?string $clientMetadataUri = null,
        array $clientMetadata = [],
    ): void {
        (new OAuthRouteRegistrar)->register($client, $handler, $middleware, $connectUri, $callbackUri, $clientMetadataUri, $clientMetadata);
    }

    /**
     * Get a local server factory.
     */
    public function getLocalServer(string $handle): ?callable
    {
        return $this->localServers[$handle] ?? null;
    }

    /**
     * Get a registered HTTP server route.
     */
    public function getWebServer(string $route): ?Route
    {
        return $this->httpServers[$route] ?? null;
    }

    /**
     * Get the registered servers.
     *
     * @return array<string, callable|Route>
     */
    public function servers(): array
    {
        return array_merge(
            $this->localServers,
            $this->httpServers,
        );
    }

    /**
     * Register server-side OAuth discovery and registration routes.
     *
     * Boot-only. Routes and Passport's challenge resolver persist for the worker lifetime.
     */
    public function oauthRoutes(string $oauthPrefix = 'oauth', bool $registration = true): void
    {
        static::ensureMcpScope();
        $hasExactProtectedResourceRoute = $this->hasGetRoute('.well-known/oauth-protected-resource');

        if (class_exists(Passport::class)) {
            if ($registration && Passport::$dynamicClientRegistrationEnabled) {
                throw new LogicException('Choose MCP or Passport client registration. Use Mcp::oauthRoutes(registration: false) with Passport dynamic registration.');
            }

            $challenges = app(AddWwwAuthenticateHeader::class);
            Passport::resolveResourceMetadataUsing(static fn (Request $request): ?string => $challenges->resourceMetadataUrl($request));
        }

        if (! $hasExactProtectedResourceRoute) {
            Router::get('/.well-known/oauth-protected-resource', static fn (): JsonResponse => response()->json(static::protectedResourceMetadata('')))
                ->name('mcp.oauth.protected-resource');
        }

        // REMOVED: Passport owns authorization-server metadata, including issuer-path discovery.

        Router::get('/.well-known/oauth-protected-resource/{path}', static function (Route $route): JsonResponse {
            $path = $route->parameter('path');

            return response()->json(static::protectedResourceMetadata(is_string($path) ? $path : ''));
        })
            ->where('path', '.*')
            ->name('mcp.oauth.protected-resource.nested');

        if ($registration && class_exists(Passport::class)) {
            Router::post($oauthPrefix . '/register', OAuthRegisterController::class)
                ->name('passport.clients.register')
                ->middleware([...config()->array('passport.middleware'), 'throttle:passport']);
        }
    }

    // REMOVED: authorizationServerMetadata(); use Passport's selected server metadata.

    /**
     * Get discovery metadata for a protected resource.
     *
     * @return array<string, array<int, string>|string>
     */
    protected static function protectedResourceMetadata(string $path): array
    {
        $issuer = config('mcp.authorization_server');

        if ($issuer === null && class_exists(Passport::class)) {
            $issuer = app(ServerFactory::class)->settings()->issuer;
        }

        if ($issuer === null) {
            throw new LogicException('Configure a Passport issuer or an external mcp.authorization_server for MCP OAuth discovery.');
        }

        return [
            'resource' => url('/' . $path),
            'authorization_servers' => [$issuer],
            'scopes_supported' => [self::OAUTH_SCOPE],
        ];
    }

    /**
     * Determine whether an exact GET route is already registered.
     */
    protected function hasGetRoute(string $uri): bool
    {
        $uri = trim(Router::getLastGroupPrefix() . '/' . $uri, '/');
        $groups = Router::getGroupStack();
        $domain = end($groups)['domain'] ?? null;

        foreach (Router::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri
                && in_array('GET', $route->methods(), true)
                && ($route->getDomain() === null || $route->getDomain() === $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add the MCP scope when Passport is installed.
     *
     * Boot-only. Passport scopes persist for the worker lifetime.
     *
     * @return array<string, string>
     */
    public static function ensureMcpScope(): array
    {
        if (class_exists(Passport::class) === false) {
            return [];
        }

        $current = Passport::$scopes;

        if (! array_key_exists(self::OAUTH_SCOPE, $current)) {
            $current[self::OAUTH_SCOPE] = 'Use MCP server';
            Passport::tokensCan($current);
        }

        return $current;
    }

    /**
     * Get the client manager.
     */
    protected function clientManager(): ClientManager
    {
        return Container::getInstance()->make(ClientManager::class);
    }

    /**
     * Start a server on its transport.
     *
     * @param class-string<Server> $serverClass
     * @param callable(): Transport $transportFactory
     */
    protected static function startServer(string $serverClass, callable $transportFactory): mixed
    {
        $transport = $transportFactory();

        $server = Container::getInstance()->make($serverClass, [
            'transport' => $transport,
        ]);

        $server->start();

        return $transport->run();
    }
}
