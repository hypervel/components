<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HttpResponse;
use Hypervel\Passport\Bridge\AccessTokenRepository;
use Hypervel\Passport\Bridge\TokenIssuance;
use Hypervel\Passport\Client;
use Hypervel\Passport\ClientAuthenticator;
use Hypervel\Passport\Http\RequestParameters;
use Hypervel\Passport\Http\Responses\AuthorizationResponse;
use Hypervel\Passport\Passport;
use Hypervel\Passport\RefreshToken;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\Token;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ServerRequestInterface;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Response;

class TokenManagementController
{
    /**
     * Create the token revocation and introspection endpoints.
     */
    public function __construct(
        protected ClientAuthenticator $authenticator,
        protected AccessTokenRepository $tokens,
        protected ServerFactory $servers,
        protected AuthorizationResponse $responses,
    ) {
    }

    /**
     * Revoke a credential without revealing whether it belongs to another client.
     */
    public function revoke(Request $request, ServerRequestInterface $psrRequest): Response
    {
        try {
            [$client, $value] = $this->authenticate($request, $psrRequest);
            $found = $this->findCredential($value);

            if ($found !== null) {
                [$token, $claims] = $found;
                $owner = $token instanceof Token ? $token->client_id : $claims['client_id'];

                if ($client->getKey() === $owner) {
                    if ($token instanceof RefreshToken) {
                        // Rotation may have consumed this token while the client was logging out.
                        TokenIssuance::revokeRefreshTokenFamily($token->family_id);
                    } else {
                        $this->tokens->revokeAccessToken($token->getKey());
                    }
                }
            }

            return new HttpResponse('', 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthServerException $exception) {
            return $this->responses->error($exception, null, null, 'query');
        }
    }

    /**
     * Describe a live credential to its owner or an authorized resource server.
     */
    public function introspect(Request $request, ServerRequestInterface $psrRequest): Response
    {
        try {
            [$client, $value] = $this->authenticate($request, $psrRequest);

            if (! $client->confidential()) {
                throw OAuthServerException::invalidClient($psrRequest);
            }

            $found = $this->findCredential($value);
            $metadata = $found === null ? ['active' => false] : $this->metadata($client, ...$found);

            return new JsonResponse($metadata, 200, ['Cache-Control' => 'no-store', 'Pragma' => 'no-cache']);
        } catch (OAuthServerException $exception) {
            return $this->responses->error($exception, null, null, 'query');
        }
    }

    /**
     * Authenticate a form request before examining its target credential.
     *
     * @return array{Client, string}
     */
    protected function authenticate(Request $request, ServerRequestInterface $psrRequest): array
    {
        $parameters = RequestParameters::fromForm($request);
        $client = $this->authenticator->authenticate($psrRequest->withParsedBody($parameters));
        $token = $parameters['token'] ?? '';

        if ($token === '') {
            throw OAuthServerException::invalidRequest('token');
        }

        return [$client, $token];
    }

    /**
     * Verify and locate a credential without applying its current authorization policy.
     *
     * @return null|array{RefreshToken|Token, array<string, mixed>}
     */
    protected function findCredential(#[SensitiveParameter] string $value): ?array
    {
        // JWTs contain dots; the grant cipher uses hexadecimal. A type hint cannot change this format.
        if (str_contains($value, '.')) {
            try {
                $claims = $this->servers->tokenValidator()->validateToken($value)->claims()->all();
            } catch (OAuthServerException) {
                return null;
            }

            $token = Passport::tokenModel()::findToken($claims['jti']);

            return $token === null ? null : [$token, $claims];
        }

        try {
            $claims = json_decode($this->servers->decryptToken($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (WrongKeyOrModifiedCiphertextException) {
            return null;
        }

        // Authorization codes use the same cipher; only a refresh payload identifies a refresh row.
        if (! is_string($claims['refresh_token_id'] ?? null) || $claims['expire_time'] < time()) {
            return null;
        }

        $token = Passport::refreshToken()->newQuery()->useWritePdo()->find($claims['refresh_token_id']);

        return $token === null ? null : [$token, $claims];
    }

    /**
     * Return only the metadata this authenticated client may inspect.
     *
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    protected function metadata(Client $client, Token|RefreshToken $token, array $claims): array
    {
        if ($token instanceof RefreshToken) {
            if ($token->revoked || $client->getKey() !== $claims['client_id']) {
                return ['active' => false];
            }

            return array_filter([
                'active' => true,
                'client_id' => $claims['client_id'],
                'scope' => implode(' ', $claims['scopes']),
                'sub' => $claims['user_id'] ?? null,
                'iss' => $this->servers->settings()->issuer,
                'exp' => $claims['expire_time'],
                'jti' => $token->getKey(),
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($this->tokens->isAccessTokenRevoked($token->getKey())
            || ($client->getKey() !== $token->client_id
                && ! (Passport::$introspectionAuthorizationCallback !== null
                    && (Passport::$introspectionAuthorizationCallback)($client, $token)))) {
            return ['active' => false];
        }

        return array_filter([
            'active' => true,
            'client_id' => $token->client_id,
            'scope' => implode(' ', $claims['scopes']),
            'token_type' => isset($claims['cnf']['jkt']) ? 'DPoP' : 'Bearer',
            'cnf' => $claims['cnf'] ?? null,
            'sub' => $claims['sub'] ?? null,
            'aud' => $claims['aud'],
            'iss' => $claims['iss'] ?? null,
            'exp' => $claims['exp']->getTimestamp(),
            'iat' => isset($claims['iat']) ? $claims['iat']->getTimestamp() : null,
            'nbf' => isset($claims['nbf']) ? $claims['nbf']->getTimestamp() : null,
            'jti' => $token->getKey(),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
