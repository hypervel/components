<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

/**
 * Unlike Laravel, the templates are variant, so an accessor can declare a wider type than its
 * getter returns, such as Attribute<string, never> for a getter returning a non-empty string.
 * Variance requires the callbacks to be read-only to analysis; runtime assignment is unchanged.
 *
 * @template-covariant TGet
 * @template-contravariant TSet
 */
class Attribute
{
    /**
     * The attribute accessor.
     *
     * @var null|(callable(mixed, array<string, mixed>): TGet)
     * @readonly
     */
    public mixed $get;

    /**
     * The attribute mutator.
     *
     * @var null|(callable(TSet, array<string, mixed>): mixed)
     * @readonly
     */
    public mixed $set;

    /**
     * Indicates if caching is enabled for this attribute.
     */
    public bool $withCaching = false;

    /**
     * Indicates if caching of objects is enabled for this attribute.
     */
    public bool $withObjectCaching = true;

    /**
     * Create a new attribute accessor / mutator.
     *
     * @template TValue
     *
     * @param null|(callable(TValue, array<string, mixed>): TGet) $get
     * @param null|(callable(TSet, array<string, mixed>): mixed) $set
     */
    public function __construct(?callable $get = null, ?callable $set = null)
    {
        $this->get = $get;
        $this->set = $set;
    }

    /**
     * Create a new attribute accessor / mutator.
     *
     * @template TValue
     * @template TMakeGet = never
     * @template TMakeSet = never
     *
     * @param null|(callable(TValue, array<string, mixed>): TMakeGet) $get
     * @param null|(callable(TMakeSet, array<string, mixed>): mixed) $set
     * @return static<TMakeGet, TMakeSet>
     */
    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static($get, $set);
    }

    /**
     * Create a new attribute accessor.
     *
     * @template TValue
     * @template TMakeGet
     *
     * @param callable(TValue, array<string, mixed>): TMakeGet $get
     * @return static<TMakeGet, never>
     */
    public static function get(callable $get): static
    {
        return new static($get);
    }

    /**
     * Create a new attribute mutator.
     *
     * @template TMakeSet
     *
     * @param callable(TMakeSet, array<string, mixed>): mixed $set
     * @return static<never, TMakeSet>
     */
    public static function set(callable $set): static
    {
        return new static(null, $set);
    }

    /**
     * Disable object caching for the attribute.
     *
     * @return $this
     */
    public function withoutObjectCaching(): static
    {
        $this->withObjectCaching = false;

        return $this;
    }

    /**
     * Enable caching for the attribute.
     *
     * @return $this
     */
    public function shouldCache(): static
    {
        $this->withCaching = true;

        return $this;
    }
}
