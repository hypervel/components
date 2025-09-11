<?php

declare(strict_types=1);

namespace Hypervel\Database\Eloquent\Concerns;

use Hyperf\Contract\Castable;
use Hyperf\Contract\CastsAttributes;
use Hyperf\Contract\CastsInboundAttributes;

trait HasAttributes
{
    /**
     * The cache of the casters.
     */
    protected static array $casterCache = [];

    /**
     * The cache of the casts.
     */
    protected static ?array $castsCache = null;

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): CastsAttributes|CastsInboundAttributes
    {
        $castType = $this->getCasts()[$key];
        if ($caster = static::$casterCache[$castType] ?? null) {
            return $caster;
        }

        $arguments = [];

        $castClass = $castType;
        if (is_string($castClass) && str_contains($castClass, ':')) {
            $segments = explode(':', $castClass, 2);

            $castClass = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($castClass, Castable::class)) {
            $castClass = $castClass::castUsing();
        }

        if (is_object($castClass)) {
            return static::$casterCache[$castType] = $castClass;
        }

        return static::$casterCache[$castType] = new $castClass(...$arguments);
    }

    /**
     * Get the casts array.
     */
    public function getCasts(): array
    {
        if (! is_null(static::$castsCache)) {
            return static::$castsCache;
        }

        if ($this->getIncrementing()) {
            return static::$castsCache = array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts, $this->casts());
        }

        return static::$castsCache = $this->casts;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
