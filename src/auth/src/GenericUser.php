<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Hypervel\Auth\Contracts\Authenticatable;

class GenericUser implements Authenticatable
{
    /**
     * All of the user's attributes.
     */
    protected array $attributes;

    /**
     * Create a new generic User object.
     */
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * Get the name of the unique identifier for the user.
     */
    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    /**
     * Get the unique identifier for the user.
     */
    public function getAuthIdentifier(): mixed
    {
        return $this->attributes[$this->getAuthIdentifierName()];
    }

    /**
     * Get the password for the user.
     */
    public function getAuthPassword(): string
    {
        return $this->attributes['password'];
    }

    /**
     * Dynamically access the user's attributes.
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->attributes[$key];
    }

    /**
     * Dynamically set an attribute on the user.
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Dynamically check if a value is set on the user.
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Dynamically unset a value on the user.
     *
     * @param string $key
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }
}
