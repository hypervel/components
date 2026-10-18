<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Hypervel\Auth\AuthManager;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passport\AccessToken;
use Hypervel\Passport\Bridge\BearerRequestFactory;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientMetadataValidator;
use Hypervel\Passport\Contracts\OAuthenticatable;
use Hypervel\Passport\Exceptions\InvalidClientMetadataException;
use Hypervel\Passport\Http\AuthenticationChallenge;
use Hypervel\Passport\Passport;
use Hypervel\Passport\ServerFactory;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use JsonException;
use League\OAuth2\Server\Exception\OAuthServerException;
use RuntimeException;
use Throwable;

class ClientRegistrationController
{
    use HandlesOAuthErrors;

    /**
     * Create the dynamic client registration endpoint.
     */
    public function __construct(
        protected ClientMetadataValidator $metadata,
        protected ServerFactory $servers,
        protected AuthManager $auth,
        protected Repository $config,
        protected AuthenticationChallenge $challenges,
    ) {
    }

    /**
     * Register a client and return its effective metadata and new credentials.
     */
    public function store(Request $request): JsonResponse
    {
        return $this->withErrorHandling(function () use ($request): JsonResponse {
            try {
                $token = $this->provisioningToken($request);
                try {
                    $submitted = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw InvalidClientMetadataException::invalidClientMetadata('The registration body must be a JSON object.');
                }

                if (! is_array($submitted) || ! str_starts_with(ltrim($request->getContent()), '{')) {
                    throw InvalidClientMetadataException::invalidClientMetadata('The registration body must be a JSON object.');
                }

                $metadata = $this->metadata->validate($submitted, token: $token);
                $scopes = $metadata['scope'] === '' ? [] : explode(' ', $metadata['scope']);

                if ($token === null && (array_diff($metadata['grant_types'], ['authorization_code', 'refresh_token']) !== []
                    || array_diff($scopes, $this->config->array('passport.registration.scopes')) !== [])) {
                    throw InvalidClientMetadataException::invalidClientMetadata('The requested grants or scopes require authorized registration.');
                }

                $client = $this->createClient($metadata, $scopes, $token, $request);
                $result = [];

                // Return registered values after model normalization, without exposing unrelated attributes.
                foreach (array_keys($metadata) as $name) {
                    $value = match ($name) {
                        'client_name' => $client->name,
                        'scope' => implode(' ', $client->scopes),
                        'response_types' => in_array('authorization_code', $client->grant_types, true) ? ['code'] : [],
                        default => $client->getAttribute($name),
                    };

                    if ($value !== null) {
                        $result[$name] = $value;
                    }
                }

                $result += [
                    'client_id' => $client->getKey(),
                    // Issuance does not depend on custom client models retaining Eloquent timestamps.
                    'client_id_issued_at' => Date::now()->getTimestamp(),
                ];

                if ($client->plainSecret !== null) {
                    $result['client_secret'] = $client->plainSecret;
                    $result['client_secret_expires_at'] = 0;
                }

                return new JsonResponse($result, 201, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
            } catch (InvalidClientMetadataException $exception) {
                return new JsonResponse([
                    'error' => $exception->error,
                    'error_description' => $exception->getMessage(),
                ], 400, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
            }
        });
    }

    /**
     * Require explicit provisioning scope when a caller supplies an access token.
     */
    protected function provisioningToken(Request $request): ?AccessToken
    {
        if (! $request->headers->has('Authorization')) {
            return null;
        }

        if (BearerRequestFactory::credentials($request->headers->get('Authorization') ?? '') === null) {
            throw $this->bearerChallenge($request);
        }

        try {
            $validated = $this->servers->validateAuthenticatedRequest($request);
        } catch (OAuthServerException $exception) {
            throw $this->bearerChallenge($request, $exception->getErrorType() === 'invalid_dpop_proof' ? 'invalid_dpop_proof' : 'invalid_token', $exception);
        }

        $token = AccessToken::fromPsrRequest($validated);

        if ($token->oauth_audiences !== [$token->oauth_client_id]
            && ! in_array(url()->current(), $token->oauth_audiences, true)) {
            throw $this->bearerChallenge($request, 'invalid_token');
        }

        // Wildcard scopes and browser cookies are not provisioning credentials.
        if (! in_array(Passport::REGISTRATION_SCOPE, $token->oauth_scopes, true)) {
            throw $this->bearerChallenge($request, 'insufficient_scope');
        }

        return $token;
    }

    /**
     * Reject provisioning credentials with their protocol's status and challenge.
     */
    protected function bearerChallenge(Request $request, ?string $error = null, ?Throwable $previous = null): HttpResponseException
    {
        $insufficientScope = $error === 'insufficient_scope';

        return new HttpResponseException(new JsonResponse(
            $error === null ? null : ['error' => $error],
            $insufficientScope ? 403 : 401,
            [
                'Cache-Control' => 'no-store',
                'Pragma' => 'no-cache',
                'WWW-Authenticate' => $this->challenges->header($request, $error, $insufficientScope ? [Passport::REGISTRATION_SCOPE] : []),
            ],
        ), $previous);
    }

    /**
     * Persist validated metadata using the authenticated user's client relation when present.
     *
     * @param array<string, mixed> $metadata
     * @param list<string> $scopes
     */
    protected function createClient(array $metadata, array $scopes, ?AccessToken $token, Request $request): Client
    {
        $attributes = [
            ...Arr::except($metadata, ['client_name', 'scope', 'response_types']),
            'name' => $metadata['client_name'],
            'scopes' => $scopes,
            'secret' => in_array($metadata['token_endpoint_auth_method'], ['client_secret_basic', 'client_secret_post'], true)
                ? Str::random(40) : null,
            'revoked' => false,
            'third_party' => true,
        ];

        if ($token?->user_id !== null) {
            // Use the credential's provider, never the default guard or the client-ID JWT subject of machine tokens.
            $provider = $token->provider;
            $owner = $provider === null ? null : $this->auth->createUserProvider($provider)?->retrieveById($token->user_id);

            if (! $owner instanceof OAuthenticatable) {
                throw $this->bearerChallenge($request, 'invalid_token');
            }

            $client = $owner->oauthApps()->make()->forceFill($attributes);
        } else {
            $client = Passport::client()->forceFill($attributes);
        }

        if (! $client->save()) {
            throw new RuntimeException('Client registration was canceled by a model observer.');
        }

        return $client;
    }
}
