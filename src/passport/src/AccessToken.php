<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Passport\Contracts\ScopeAuthorizable;
use Hypervel\Passport\Events\AccessTokenRevoked;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Traits\ForwardsCalls;
use JsonSerializable;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @template TValue
 *
 * @implements Arrayable<array<string, TValue>>
 *
 * @mixin Token
 *
 * @property string $oauth_access_token_id
 * @property string $oauth_client_id
 * @property string $oauth_user_id
 * @property string[] $oauth_scopes
 */
class AccessToken implements ScopeAuthorizable, Arrayable, Jsonable, JsonSerializable, Transient
{
    use ResolvesInheritedScopes;
    use ForwardsCalls;

    /**
     * The token instance.
     */
    protected ?Token $token = null;

    /**
     * All the attributes set on the access token instance.
     *
     * @var array<string, TValue>
     */
    protected array $attributes = [];

    /**
     * Create a new access token instance.
     *
     * @param array<string, TValue> $attributes
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * Create a new access token instance from the incoming PSR-7 request.
     */
    public static function fromPsrRequest(ServerRequestInterface $request): static
    {
        return new static($request->getAttributes());
    }

    /**
     * Determine if the token has a given scope.
     */
    public function can(string $scope): bool
    {
        if (empty($this->attributes['oauth_scopes'])) {
            return false;
        }

        return in_array('*', $this->attributes['oauth_scopes'], true)
            || $this->scopeExistsIn($scope, $this->attributes['oauth_scopes']);
    }

    /**
     * Determine if the token is missing a given scope.
     */
    public function cant(string $scope): bool
    {
        return ! $this->can($scope);
    }

    /**
     * Determine if the token is a transient JWT token.
     */
    public function transient(): bool
    {
        return false;
    }

    /**
     * Revoke the token instance.
     */
    public function revoke(): bool
    {
        if ($this->token) {
            return $this->token->revoke();
        }

        if (isset($this->attributes['oauth_access_token_id'])) {
            $token = Passport::token();
            $tokenId = $this->attributes['oauth_access_token_id'];
            $revoked = (bool) $token->newQuery()->whereKey($tokenId)->where('revoked', false)->update(['revoked' => true]);

            if ($revoked) {
                $token::clearTokenCache($tokenId);
                $token->getConnection()->afterCommitOrNow(static function () use ($tokenId): void {
                    if (Event::hasListeners(AccessTokenRevoked::class)) {
                        Event::dispatch(new AccessTokenRevoked($tokenId));
                    }
                });
            }

            return $revoked;
        }

        return false;
    }

    /**
     * Get the token instance.
     */
    protected function getToken(): ?Token
    {
        if (isset($this->attributes['oauth_access_token_id'])) {
            // The model lookup owns memo invalidation and bypasses caches inside transactions.
            return $this->token = Passport::tokenModel()::findToken($this->attributes['oauth_access_token_id']);
        }

        return null;
    }

    /**
     * Convert the access token instance to an array.
     *
     * @return array<string, TValue>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * Convert the object into something JSON serializable.
     *
     * @return array<string, TValue>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the access token instance to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Dynamically determine if an attribute is set.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]) || isset($this->getToken()->{$key});
    }

    /**
     * Dynamically retrieve the value of an attribute.
     */
    public function __get(string $key): mixed
    {
        if (array_key_exists($key, $this->attributes)) {
            return $this->attributes[$key];
        }

        return $this->getToken()?->{$key};
    }

    /**
     * Pass dynamic methods onto the token instance.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->getToken(), $method, $parameters);
    }
}
