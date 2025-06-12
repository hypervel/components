<?php

declare(strict_types=1);

namespace Hypervel\Socialite;

use ArrayAccess;
use Hypervel\Socialite\Contracts\User;

abstract class AbstractUser implements ArrayAccess, User
{
    /**
     * The unique identifier for the user.
     */
    public mixed $id;

    /**
     * The user's nickname / username.
     */
    public ?string $nickname = null;

    /**
     * The user's full name.
     */
    public ?string $name = null;

    /**
     * The user's e-mail address.
     */
    public ?string $email = null;

    /**
     * The user's avatar image URL.
     */
    public ?string $avatar = null;

    /**
     * The user's raw attributes.
     */
    public array $user = [];

    /**
     * The user's other attributes.
     */
    public array $attributes = [];

    /**
     * Get the unique identifier for the user.
     */
    public function getId(): mixed
    {
        return $this->id;
    }

    /**
     * Get the nickname / username for the user.
     */
    public function getNickname(): ?string
    {
        return $this->nickname;
    }

    /**
     * Get the full name of the user.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Get the e-mail address of the user.
     */
    public function getEmail(): ?string
    {
        return $this->email;
    }

    /**
     * Get the avatar / image URL for the user.
     */
    public function getAvatar(): ?string
    {
        return $this->avatar;
    }

    /**
     * Get the raw user array.
     */
    public function getRaw(): array
    {
        return $this->user;
    }

    /**
     * Set the raw user array from the provider.
     */
    public function setRaw(array $user): static
    {
        $this->user = $user;

        return $this;
    }

    /**
     * Map the given array onto the user's properties.
     */
    public function map(array $attributes): static
    {
        $this->attributes = $attributes;

        foreach ($attributes as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }

        return $this;
    }

    /**
     * Determine if the given raw user attribute exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->user);
    }

    /**
     * Get the given key from the raw user.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->user[$offset];
    }

    /**
     * Set the given attribute on the raw user array.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->user[$offset] = $value;
    }

    /**
     * Unset the given value from the raw user array.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->user[$offset]);
    }

    /**
     * Get a user attribute value dynamically.
     */
    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
