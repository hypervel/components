<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Facades;

use Closure;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Mcp\Client client(string $name)
 * @method static array<string, string> ensureMcpScope()
 * @method static void flushMacros()
 * @method static callable|null getLocalServer(string $handle)
 * @method static \Hypervel\Routing\Route|null getWebServer(string $route)
 * @method static bool hasMacro(string $name)
 * @method static void local(string $handle, string $serverClass)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void oauthRoutes(string $oauthPrefix = 'oauth', bool $registration = true)
 * @method static void oAuthRoutesFor(string $client, array|Closure $handler, array<int, string>|string $middleware = 'web', string|null $connectUri = null, string|null $callbackUri = null, string|null $clientMetadataUri = null, array<string, mixed> $clientMetadata = [])
 * @method static void registerClient(string $name, Closure $factory)
 * @method static array<string, callable|\Hypervel\Routing\Route> servers()
 * @method static \Hypervel\Routing\Route web(string $route, string $serverClass)
 *
 * @see \Hypervel\Mcp\Server\Registrar
 */
class Mcp extends Facade
{
    /**
     * Get the registered MCP registrar.
     *
     * @return class-string<Registrar>
     */
    protected static function getFacadeAccessor(): string
    {
        return Registrar::class;
    }
}
