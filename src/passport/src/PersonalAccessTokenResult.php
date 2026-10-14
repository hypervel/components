<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Str;
use JsonSerializable;

/**
 * @template TValue
 *
 * @implements Arrayable<array<string, TValue>>
 *
 * @property string $accessTokenId
 * @property string $accessToken
 * @property string $tokenType
 * @property int $expiresIn
 */
class PersonalAccessTokenResult implements Arrayable, Jsonable, JsonSerializable, Transient
{
    /**
     * The token instance.
     */
    protected ?Token $token = null;

    /**
     * All the attributes set on the personal access token response.
     *
     * @var array<string, TValue>
     */
    protected array $attributes = [];

    /**
     * Create a new result instance.
     *
     * @param array<string, TValue> $attributes
     */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[Str::camel($key)] = $value;
        }
    }

    /**
     * Get the token instance.
     */
    public function getToken(): ?Token
    {
        // The token has just been issued and may not have reached a read replica yet.
        return $this->token ??= Passport::token()->newQuery()->useWritePdo()->find($this->accessTokenId);
    }

    /**
     * Get the instance as an array.
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
     * Convert the object to its JSON representation.
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
        return isset($this->attributes[$key]);
    }

    /**
     * Dynamically retrieve the value of an attribute.
     */
    public function __get(string $key): mixed
    {
        if ($key === 'token') {
            return $this->getToken();
        }

        return $this->attributes[$key] ?? null;
    }
}
