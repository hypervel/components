<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Client\OAuth;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Mcp\Client\ClientManager;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Mcp\WebClient;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Route as Router;

class OAuthRouteRegistrar
{
    /**
     * Get an OAuth route URL using the configured application origin.
     */
    public static function url(string $name): string
    {
        $root = rtrim(config()->string('app.url'), '/');

        return $root === '' ? route($name) : $root . route($name, [], false);
    }

    /**
     * Register connection, callback and public metadata routes for a named client.
     *
     * Boot-only. Routes persist for the worker lifetime and affect subsequent requests.
     *
     * @param array{0: class-string, 1: string}|Closure(string, TokenSet): mixed $handler
     * @param array<int, string>|string $middleware
     * @param array<string, mixed> $clientMetadata
     */
    public function register(
        string $client,
        Closure|array $handler,
        array|string $middleware = 'web',
        ?string $connectUri = null,
        ?string $callbackUri = null,
        ?string $clientMetadataUri = null,
        array $clientMetadata = [],
    ): void {
        if (is_array($handler)) {
            $handler = $handler[0] . '@' . $handler[1];
        }

        $connect = Router::get($connectUri ?? "mcp/{$client}/connect", function () use ($client): mixed {
            $resourceMetadata = request()->query('resource_metadata');
            $scope = request()->query('scope');

            return $this->webClient($client)->oAuthClient(
                is_string($resourceMetadata) && $resourceMetadata !== '' ? $resourceMetadata : null,
                is_string($scope) && $scope !== '' ? $scope : null,
            )->redirect();
        });

        $connect->name("mcp.oauth.{$client}.connect")->middleware($middleware);

        $callback = Router::get($callbackUri ?? "mcp/oauth/{$client}/callback", function () use ($client, $handler): mixed {
            $oauth = $this->webClient($client)->oAuthClient();
            $token = $oauth->exchangeCallback();

            $result = Container::getInstance()->call($handler, [
                'provider' => $client,
                'client' => $client,
                'token' => $token,
                'returnTo' => $oauth->returnTo(),
            ]);

            return $result ?? redirect($oauth->returnTo() ?? '/');
        });

        $callback->name("mcp.oauth.{$client}.callback")->middleware($middleware);

        $document = Router::get($clientMetadataUri ?? "mcp/oauth/{$client}/client-metadata.json", fn (): JsonResponse => (new JsonResponse([
            'client_name' => trim(config()->string('app.name') . ' MCP Client'),
            'client_uri' => rtrim(config()->string('app.url'), '/'),
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            ...Arr::except($clientMetadata, ['client_secret', 'client_secret_expires_at', 'registration_access_token']),
            'client_id' => self::url("mcp.oauth.{$client}.client-metadata"),
            'redirect_uris' => array_values(array_unique([
                self::url("mcp.oauth.{$client}.callback"),
                ...array_map(strval(...), (array) ($clientMetadata['redirect_uris'] ?? [])),
            ])),
            'token_endpoint_auth_method' => 'none',
        ]))->setPublic()->setMaxAge(3600));

        $document->name("mcp.oauth.{$client}.client-metadata");

        Router::getRoutes()->refreshNameLookups();
    }

    /**
     * Resolve a named client that supports OAuth.
     */
    protected function webClient(string $name): WebClient
    {
        $client = Container::getInstance()->make(ClientManager::class)->client($name);

        if (! $client instanceof WebClient) {
            throw new ClientException("MCP client [{$name}] does not support OAuth.");
        }

        return $client;
    }
}
