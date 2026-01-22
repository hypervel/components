<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hypervel\Context\Context;

trait GuardsAttributes
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected array $fillable = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected array $guarded = ['*'];

    /**
     * The actual columns that exist on the database and can be guarded.
     *
     * @var array<class-string,list<string>>
     */
    protected static array $guardableColumns = [];

    /**
     * Get the fillable attributes for the model.
     *
     * @return array<string>
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Set the fillable attributes for the model.
     *
     * @param  array<string>  $fillable
     */
    public function fillable(array $fillable): static
    {
        $this->fillable = $fillable;

        return $this;
    }

    /**
     * Merge new fillable attributes with existing fillable attributes on the model.
     *
     * @param  array<string>  $fillable
     */
    public function mergeFillable(array $fillable): static
    {
        $this->fillable = array_values(array_unique(array_merge($this->fillable, $fillable)));

        return $this;
    }

    /**
     * Get the guarded attributes for the model.
     *
     * @return array<string>
     */
    public function getGuarded(): array
    {
        return static::isUnguarded()
            ? []
            : $this->guarded;
    }

    /**
     * Set the guarded attributes for the model.
     *
     * @param  array<string>  $guarded
     */
    public function guard(array $guarded): static
    {
        $this->guarded = $guarded;

        return $this;
    }

    /**
     * Merge new guarded attributes with existing guarded attributes on the model.
     *
     * @param  array<string>  $guarded
     */
    public function mergeGuarded(array $guarded): static
    {
        $this->guarded = array_values(array_unique(array_merge($this->guarded, $guarded)));

        return $this;
    }

    /**
     * Disable all mass assignable restrictions.
     *
     * Uses Context for coroutine-safe state management.
     */
    public static function unguard(bool $state = true): void
    {
        Context::set(self::UNGUARDED_CONTEXT_KEY, $state);
    }

    /**
     * Enable the mass assignment restrictions.
     */
    public static function reguard(): void
    {
        Context::set(self::UNGUARDED_CONTEXT_KEY, false);
    }

    /**
     * Determine if the current state is "unguarded".
     */
    public static function isUnguarded(): bool
    {
        return (bool) Context::get(self::UNGUARDED_CONTEXT_KEY, false);
    }

    /**
     * Run the given callable while being unguarded.
     *
     * Uses Context for coroutine-safe state management, ensuring concurrent
     * requests don't interfere with each other's guarding state.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::isUnguarded()) {
            return $callback();
        }

        $wasUnguarded = Context::get(self::UNGUARDED_CONTEXT_KEY, false);
        Context::set(self::UNGUARDED_CONTEXT_KEY, true);

        try {
            return $callback();
        } finally {
            Context::set(self::UNGUARDED_CONTEXT_KEY, $wasUnguarded);
        }
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (static::isUnguarded()) {
            return true;
        }

        // If the key is in the "fillable" array, we can of course assume that it's
        // a fillable attribute. Otherwise, we will check the guarded array when
        // we need to determine if the attribute is black-listed on the model.
        if (in_array($key, $this->getFillable())) {
            return true;
        }

        // If the attribute is explicitly listed in the "guarded" array then we can
        // return false immediately. This means this attribute is definitely not
        // fillable and there is no point in going any further in this method.
        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->getFillable()) &&
            ! str_contains($key, '.') &&
            ! str_starts_with($key, '_');
    }

    /**
     * Determine if the given key is guarded.
     */
    public function isGuarded(string $key): bool
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        return $this->getGuarded() == ['*'] ||
               ! empty(preg_grep('/^'.preg_quote($key, '/').'$/i', $this->getGuarded())) ||
               ! $this->isGuardableColumn($key);
    }

    /**
     * Determine if the given column is a valid, guardable column.
     */
    protected function isGuardableColumn(string $key): bool
    {
        if ($this->hasSetMutator($key) || $this->hasAttributeSetMutator($key) || $this->isClassCastable($key)) {
            return true;
        }

        if (! isset(static::$guardableColumns[get_class($this)])) {
            $columns = $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable());

            if (empty($columns)) {
                return true;
            }

            static::$guardableColumns[get_class($this)] = $columns;
        }

        return in_array($key, static::$guardableColumns[get_class($this)]);
    }

    /**
     * Determine if the model is totally guarded.
     */
    public function totallyGuarded(): bool
    {
        return count($this->getFillable()) === 0 && $this->getGuarded() == ['*'];
    }

    /**
     * Get the fillable attributes of a given array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->getFillable()) > 0 && ! static::isUnguarded()) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        return $attributes;
    }
}
