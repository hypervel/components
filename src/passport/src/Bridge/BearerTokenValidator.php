<?php

/**
 * @copyright   Copyright (c) Alex Bilbie
 * @license     http://mit-license.org/
 *
 * @link        https://github.com/thephpleague/oauth2-server
 */

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Exception;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token;
use Lcobucci\JWT\UnencryptedToken;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use League\OAuth2\Server\AuthorizationValidators\AuthorizationValidatorInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;

use function date_default_timezone_get;
use function preg_replace;
use function trim;

class BearerTokenValidator implements AuthorizationValidatorInterface
{
    private Configuration $jwtConfiguration;

    private LooseValidAt $validAt;

    /**
     * @var array<string, SignedWith>
     */
    private array $signatures = [];

    /**
     * Create a validator for the retained keys, ordered newest first.
     *
     * @param array<string, ParsedKey> $publicKeys
     */
    public function __construct(
        private AccessTokenRepositoryInterface $accessTokenRepository,
        array $publicKeys,
        private ?DateInterval $jwtValidAtDateLeeway = null,
    ) {
        $this->initJwtConfiguration();

        $signer = new RsaSigner;

        foreach ($publicKeys as $identifier => $key) {
            $this->signatures[$identifier] = new SignedWith($signer, $key);
        }
    }

    /**
     * Initialize the JWT configuration.
     */
    private function initJwtConfiguration(): void
    {
        $this->jwtConfiguration = Configuration::forSymmetricSigner(
            new RsaSigner,
            InMemory::plainText('empty', 'empty')
        );

        $clock = new class implements ClockInterface {
            /**
             * Return the current time.
             */
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            }
        };

        $this->validAt = new LooseValidAt($clock, $this->jwtValidAtDateLeeway);
    }

    /**
     * Validate the bearer token and append its OAuth attributes.
     */
    public function validateAuthorization(ServerRequestInterface $request): ServerRequestInterface
    {
        if ($request->hasHeader('authorization') === false) {
            throw OAuthServerException::accessDenied('Missing "Authorization" header');
        }

        $header = $request->getHeader('authorization');
        $jwt = trim((string) preg_replace('/^\s*Bearer\s/i', '', $header[0]));

        if ($jwt === '') {
            throw OAuthServerException::accessDenied('Missing "Bearer" token');
        }

        $claims = $this->validateToken($jwt)->claims();

        // Check if token has been revoked
        if ($this->accessTokenRepository->isAccessTokenRevoked($claims->get('jti'))) {
            throw OAuthServerException::accessDenied('Access token has been revoked');
        }

        // Return the request with additional attributes
        return $request
            ->withAttribute('oauth_access_token_id', $claims->get('jti'))
            ->withAttribute('oauth_client_id', $claims->get('aud')[0])
            ->withAttribute('oauth_user_id', $claims->get('sub'))
            ->withAttribute('oauth_scopes', $claims->get('scopes'));
    }

    /**
     * Verify a token's signature, lifetime and identifier without applying stored-token authorization policy.
     *
     * @internal shared by bearer authorization and token management
     */
    public function validateToken(string $jwt): UnencryptedToken
    {
        try {
            // Attempt to parse the JWT
            $token = $this->jwtConfiguration->parser()->parse($jwt);
        } catch (Exception $exception) {
            throw OAuthServerException::accessDenied($exception->getMessage(), null, $exception);
        }

        try {
            // Attempt to validate the JWT
            $this->jwtConfiguration->validator()->assert($token, $this->validAt);
            $this->validateSignature($token);
        } catch (RequiredConstraintsViolated $exception) {
            throw OAuthServerException::accessDenied('Access token could not be verified', null, $exception);
        }

        if (! $token instanceof UnencryptedToken) {
            throw OAuthServerException::accessDenied('Access token is not an instance of UnencryptedToken');
        }

        // Other signed artifacts may use these keys without identifying an access-token record.
        $identifier = $token->claims()->get('jti');

        if (! is_string($identifier) || $identifier === '') {
            throw OAuthServerException::accessDenied('Access token has no valid identifier');
        }

        return $token;
    }

    /**
     * Verify the named key, or retained keys for tokens issued without a key ID.
     */
    private function validateSignature(Token $token): void
    {
        if ($token->headers()->has('kid')) {
            $identifier = $token->headers()->get('kid');

            if (! is_string($identifier) || ! isset($this->signatures[$identifier])) {
                throw OAuthServerException::accessDenied('Access token could not be verified');
            }

            $this->jwtConfiguration->validator()->assert($token, $this->signatures[$identifier]);

            return;
        }

        foreach ($this->signatures as $constraint) {
            if ($this->jwtConfiguration->validator()->validate($token, $constraint)) {
                return;
            }
        }

        throw OAuthServerException::accessDenied('Access token could not be verified');
    }
}
