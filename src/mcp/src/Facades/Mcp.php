<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Facades;

use Closure;
use Hypervel\Mcp\Client;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Routing\Route;
use Hypervel\Support\Facades\Facade;

/**
 * @method static Route web(string $route, string $serverClass)
 * @method static void local(string $handle, string $serverClass)
 * @method static void registerClient(string $name, Closure $factory)
 * @method static Client client(string $name)
 * @method static void oAuthRoutesFor(string $client, Closure|array $handler, array|string $middleware = 'web', string|null $connectUri = null, string|null $callbackUri = null, string|null $clientMetadataUri = null, array $clientMetadata = [])
 * @method static callable|null getLocalServer(string $handle)
 * @method static Route|null getWebServer(string $route)
 * @method static array servers()
 * @method static void oauthRoutes(string $oauthPrefix = 'oauth', bool $registration = true)
 * @method static array ensureMcpScope()
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see Registrar
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
