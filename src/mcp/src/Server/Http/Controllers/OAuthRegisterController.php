<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Http\Controllers;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\BindingResolutionException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Mcp\Server\Registrar;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Passport;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class OAuthRegisterController
{
    /**
     * Register a new OAuth client for a third-party application.
     *
     * @throws BindingResolutionException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'client_name' => ['nullable', 'string', 'min:1', 'max:255'],
            'name' => ['nullable', 'string', 'min:1', 'max:255'],
            'redirect_uris' => ['required', 'array', 'min:1'],
            'redirect_uris.*' => ['bail', 'required', 'string', function (string $attribute, string $value, Closure $fail): void {
                if (! $this->isValidRedirectUri($value)) {
                    $fail($attribute . ' is not a valid URL.');

                    return;
                }

                if (! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    return;
                }

                if (in_array('*', config()->array('mcp.redirect_domains'), true)) {
                    return;
                }

                if ($this->hasLocalhostDomain() && $this->isLocalhostUrl($value)) {
                    return;
                }

                $allowedDomains = $this->allowedDomains();

                if (! Str::startsWith($value, $allowedDomains)
                    && ! in_array(Str::before($value, '?') . '/', $allowedDomains, true)) {
                    $fail($attribute . ' is not a permitted redirect domain.');
                }
            }],
            'logo_uri' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'client_uri' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $redirectError = $errors->first('redirect_uris*');

            return response()->json([
                'error' => $redirectError !== '' ? 'invalid_redirect_uri' : 'invalid_client_metadata',
                'error_description' => $redirectError !== '' ? $redirectError : $errors->first(),
            ], 400);
        }

        $validated = $validator->validated();

        if (! class_exists(ClientRepository::class)) {
            return response()->json([
                'error' => 'server_error',
                'error_description' => 'OAuth support (Passport) is not installed.',
            ], 500);
        }

        $clients = Container::getInstance()->make(ClientRepository::class);

        try {
            [$client, $metadata] = Passport::client()->getConnection()->transaction(function () use ($clients, $validated): array {
                $client = $clients->createAuthorizationCodeGrantClient(
                    name: $this->resolveClientName($validated),
                    redirectUris: $validated['redirect_uris'],
                    confidential: false,
                    enableDeviceFlow: false,
                );

                // Commit registration only after the client is classified as untrusted.
                if (! $client->forceFill(['third_party' => true])->save()) {
                    throw new RuntimeException('The client trust classification could not be saved.');
                }

                $this->grantMcpScope($client);

                return [$client, $this->persistClientMetadata($client, $validated)];
            });
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            report($throwable);

            return response()->json([
                'error' => 'server_error',
                'error_description' => 'The client could not be registered.',
            ], 500);
        }

        return response()->json([
            'client_id' => (string) $client->id,
            'grant_types' => $client->grant_types,
            'response_types' => ['code'],
            'redirect_uris' => $client->redirect_uris,
            'scope' => Registrar::OAUTH_SCOPE,
            'token_endpoint_auth_method' => 'none',
            ...$metadata,
        ], 201);
    }

    /**
     * Grant the MCP scope when the client restricts its scopes.
     */
    protected function grantMcpScope(Client $client): void
    {
        $scopes = $client->refresh()->getAttribute('scopes');

        if (! is_array($scopes) || in_array(Registrar::OAUTH_SCOPE, $scopes, true)) {
            return;
        }

        if (! $client->forceFill(['scopes' => [...$scopes, Registrar::OAUTH_SCOPE]])->save()) {
            throw new RuntimeException('The client scopes could not be saved.');
        }
    }

    /**
     * Persist supported client metadata.
     *
     * @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function persistClientMetadata(Client $client, array $validated): array
    {
        $columns = $client->getConnection()->getSchemaBuilder()->getColumnListing($client->getTable());
        $supported = array_intersect(['logo_uri', 'client_uri'], $columns);
        $metadata = array_filter(Arr::only($validated, $supported));

        if ($metadata !== [] && ! $client->forceFill($metadata)->save()) {
            throw new RuntimeException('The client metadata could not be saved.');
        }

        return array_filter($client->only($supported));
    }

    /**
     * Resolve the client name, falling back to the redirect host or a default.
     *
     * @param array<string, mixed> $validated
     */
    protected function resolveClientName(array $validated): string
    {
        return $validated['client_name']
            ?? $validated['name']
            ?? (parse_url((string) ($validated['redirect_uris'][0] ?? ''), PHP_URL_HOST) ?: 'MCP Client');
    }

    /**
     * Determine whether the redirect URI uses an allowed scheme and format.
     */
    protected function isValidRedirectUri(string $value): bool
    {
        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (! is_string($scheme)) {
            return false;
        }

        if (parse_url($value, PHP_URL_USER) !== null
            || parse_url($value, PHP_URL_PASS) !== null
            || parse_url($value, PHP_URL_FRAGMENT) !== null) {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            return Str::isUrl($value, ['http', 'https']);
        }

        /** @var array<int, string> */
        $allowedSchemes = config()->array('mcp.custom_schemes');
        $host = parse_url($value, PHP_URL_HOST);

        return in_array($scheme, $allowedSchemes, true) && is_string($host) && $host !== '';
    }

    /**
     * Determine whether the URL is an HTTP loopback callback.
     */
    protected function isLocalhostUrl(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ($parts['scheme'] ?? null) !== 'http' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return in_array($parts['host'] ?? null, ['localhost', '127.0.0.1', '[::1]'], true);
    }

    /**
     * Get the allowed redirect domains.
     *
     * @return array<int, string>
     */
    protected function allowedDomains(): array
    {
        /** @var array<int, string> */
        $allowedDomains = config()->array('mcp.redirect_domains');

        return collect($allowedDomains)
            ->map(
                fn (string $domain): string => Str::endsWith($domain, '/')
                ? $domain
                : "{$domain}/"
            )
            ->all();
    }

    /**
     * Determine whether the configured domains include a loopback host.
     */
    private function hasLocalhostDomain(): bool
    {
        /** @var array<int, string> */
        $domains = config()->array('mcp.redirect_domains');

        return collect($domains)->contains(fn (string $domain): bool => in_array(
            rtrim(Str::after($domain, '://'), '/'),
            ['localhost', '127.0.0.1', '[::1]'],
            true,
        ));
    }
}
