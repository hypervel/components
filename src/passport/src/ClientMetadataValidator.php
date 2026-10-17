<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use DomainException;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Validation\Factory;
use Hypervel\Http\Client\HttpClientException;
use Hypervel\Passport\Events\ClientMetadataValidated;
use Hypervel\Passport\Exceptions\InvalidClientMetadataException;
use Hypervel\Passport\Http\HttpsDestinationPolicy;
use Hypervel\Validation\Rule;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

class ClientMetadataValidator
{
    /**
     * Create the validator shared by registration and application management.
     */
    public function __construct(
        protected Factory $validation,
        protected Dispatcher $events,
        protected Repository $config,
        protected ServerFactory $servers,
        protected ClientKeyRepository $keys,
        protected HttpsDestinationPolicy $destinations,
    ) {
    }

    /**
     * Validate a complete metadata document without granting provisioning authority.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function validate(array $metadata, ?Client $client = null, ?AccessToken $token = null): array
    {
        $grants = ['authorization_code', 'refresh_token', 'client_credentials'];

        if (Passport::$deviceCodeGrantEnabled) {
            $grants[] = 'urn:ietf:params:oauth:grant-type:device_code';
        }

        $rules = [
            'client_name' => ['sometimes', 'required', 'string', 'max:255'],
            'redirect_uris' => ['sometimes', 'array', 'list'],
            'redirect_uris.*' => ['required', 'string', 'distinct:strict'],
            'grant_types' => ['sometimes', 'required', 'array', 'list', 'min:1'],
            'grant_types.*' => ['required', 'string', 'distinct:strict', Rule::in($grants)],
            'response_types' => ['sometimes', 'array', 'list'],
            'response_types.*' => ['required', 'string', 'distinct:strict', Rule::in(['code'])],
            'scope' => ['sometimes', 'string'],
            'application_type' => ['sometimes', 'required', Rule::in(['web', 'native'])],
            'token_endpoint_auth_method' => ['sometimes', 'required', Rule::in(ClientAuthenticator::METHODS)],
            'token_endpoint_auth_signing_alg' => ['sometimes', 'required', Rule::in(JwkValidator::ALGORITHMS)],
            'jwks' => ['sometimes', 'required', 'array'],
            'jwks_uri' => ['sometimes', 'required', 'string'],
            'dpop_bound_access_tokens' => ['sometimes', 'boolean:strict'],
            'require_pushed_authorization_requests' => ['sometimes', 'boolean:strict'],
        ];
        $validator = $this->validation->make($metadata, $rules);

        if ($validator->fails()) {
            throw InvalidClientMetadataException::invalidClientMetadata($validator->errors()->first());
        }

        $validated = $validator->validated();
        $validated += [
            'client_name' => 'OAuth Client',
            'grant_types' => ['authorization_code'],
            'redirect_uris' => [],
            'scope' => '',
            'application_type' => 'web',
            'token_endpoint_auth_method' => 'client_secret_basic',
            'dpop_bound_access_tokens' => false,
            'require_pushed_authorization_requests' => false,
        ];
        $codeGrant = in_array('authorization_code', $validated['grant_types'], true);
        $validated['response_types'] ??= $codeGrant ? ['code'] : [];

        if ($validated['response_types'] !== ($codeGrant ? ['code'] : [])) {
            throw InvalidClientMetadataException::invalidClientMetadata('The response types must match the registered grants.');
        }

        if ($codeGrant && $validated['redirect_uris'] === []) {
            throw InvalidClientMetadataException::invalidRedirectUri('Authorization code clients must register a redirect URI.');
        }

        foreach ($validated['redirect_uris'] as $uri) {
            $this->validateRedirectUri($uri, $validated['application_type']);
        }

        $scopes = $validated['scope'] === '' ? [] : explode(' ', $validated['scope']);

        foreach ($scopes as $scope) {
            if (preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+$/D', $scope) !== 1 || ! Passport::hasScope($scope)) {
                throw InvalidClientMetadataException::invalidClientMetadata('The requested scope is not supported.');
            }
        }

        $validated['scope'] = implode(' ', array_unique($scopes));

        if ($validated['token_endpoint_auth_method'] === 'none' && in_array('client_credentials', $validated['grant_types'], true)) {
            throw InvalidClientMetadataException::invalidClientMetadata('Client credentials grants require a confidential client.');
        }

        if ($validated['dpop_bound_access_tokens'] && ! $this->config->boolean('passport.dpop.enabled')) {
            throw InvalidClientMetadataException::invalidClientMetadata('DPoP is not enabled on this server.');
        }

        $validated = $this->validateKeys($validated);

        if ($this->events->hasListeners(ClientMetadataValidated::class)) {
            $event = new ClientMetadataValidated($metadata, $validated, $client, $token);
            $this->events->dispatch($event);

            // Extensions supply their own columns, never core metadata or model ownership/credentials.
            $protected = [...array_keys($rules), 'id', 'client_id', 'name', 'secret', 'client_secret',
                'client_secret_expires_at', 'client_id_issued_at', 'scopes', 'provider', 'owner_id', 'owner_type',
                'third_party', 'revoked', 'created_at', 'updated_at'];

            if (array_intersect(array_keys($event->extensions), $protected) !== []) {
                throw new LogicException('Client metadata extensions cannot replace core metadata or protected client attributes.');
            }

            $validated += $event->extensions;
        }

        return $validated;
    }

    /**
     * Validate web and native callback addresses without fetching them.
     */
    protected function validateRedirectUri(string $uri, string $applicationType): void
    {
        $parts = parse_url($uri);

        if ($parts === false || ! isset($parts['scheme']) || preg_match('/^[a-z][a-z0-9+.-]*$/iD', $parts['scheme']) !== 1
            || isset($parts['fragment'])
            || isset($parts['user']) || isset($parts['pass']) || str_contains($uri, '*')
            || preg_match('/[\x00-\x20\x7F\\\]/', $uri) === 1) {
            throw InvalidClientMetadataException::invalidRedirectUri('Redirect URIs must be absolute and cannot contain fragments, credentials or wildcards.');
        }

        $scheme = strtolower($parts['scheme']);

        if ($scheme === 'https' && filter_var($uri, FILTER_VALIDATE_URL) !== false) {
            return;
        }

        if ($applicationType === 'native') {
            if ($scheme === 'http' && in_array($parts['host'] ?? null, ['127.0.0.1', '[::1]'], true)
                && filter_var($uri, FILTER_VALIDATE_URL) !== false) {
                return;
            }

            if (str_contains($scheme, '.') && ! isset($parts['host'])
                && str_starts_with($parts['path'] ?? '', '/')) {
                return;
            }
        }

        throw InvalidClientMetadataException::invalidRedirectUri('Use HTTPS, or a native loopback or reverse-domain redirect URI.');
    }

    /**
     * Validate declared public keys and the authentication algorithm.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    protected function validateKeys(array $metadata): array
    {
        if (isset($metadata['jwks'], $metadata['jwks_uri'])) {
            throw InvalidClientMetadataException::invalidClientMetadata('Register either jwks or jwks_uri, not both.');
        }

        if ($metadata['token_endpoint_auth_method'] === 'private_key_jwt') {
            $issuer = $this->servers->settings()->issuer;

            if ($issuer === null || ! isset($metadata['jwks']) && ! isset($metadata['jwks_uri'])) {
                throw InvalidClientMetadataException::invalidClientMetadata('JWT client authentication requires a configured issuer and registered public keys.');
            }

            $metadata['token_endpoint_auth_signing_alg'] ??= 'RS256';
        } elseif (isset($metadata['token_endpoint_auth_signing_alg']) || isset($metadata['jwks']) || isset($metadata['jwks_uri'])) {
            throw InvalidClientMetadataException::invalidClientMetadata('Registered client keys and assertion algorithms require private_key_jwt authentication.');
        }

        try {
            if (isset($metadata['jwks'])) {
                if (strlen(json_encode($metadata['jwks'], JSON_THROW_ON_ERROR)) > RemoteDocumentFetcher::MAX_DOCUMENT_BYTES) {
                    throw InvalidClientMetadataException::invalidClientMetadata('The public key set exceeds the maximum size.');
                }

                $this->keys->validate($metadata['jwks'], $metadata['token_endpoint_auth_signing_alg']);
            }

            if (isset($metadata['jwks_uri'])) {
                $this->destinations->validate($metadata['jwks_uri']);
            }
        } catch (DomainException|InvalidArgumentException|UnexpectedValueException|HttpClientException $exception) {
            throw InvalidClientMetadataException::invalidClientMetadata($exception->getMessage());
        }

        return $metadata;
    }
}
