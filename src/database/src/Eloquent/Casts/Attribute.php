<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Casts;

class Attribute
{
    /**
     * The attribute accessor.
     *
     * @var callable|null
     */
    public $get;

    /**
     * The attribute mutator.
     *
     * @var callable|null
     */
    public $set;

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
     */
    public function __construct(?callable $get = null, ?callable $set = null)
    {
        $this->get = $get;
        $this->set = $set;
    }

    /**
     * Create a new attribute accessor / mutator.
     */
    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static($get, $set);
    }

    /**
     * Create a new attribute accessor.
     */
    public static function get(callable $get): static
    {
        return new static($get);
    }

    /**
     * Create a new attribute mutator.
     */
    public static function set(callable $set): static
    {
        return new static(null, $set);
    }

    /**
     * Disable object caching for the attribute.
     */
    public function withoutObjectCaching(): static
    {
        $this->withObjectCaching = false;

        return $this;
    }

    /**
     * Enable caching for the attribute.
     */
    public function shouldCache(): static
    {
        $this->withCaching = true;

        return $this;
    }
}
