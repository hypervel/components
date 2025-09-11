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
    protected static array $castsCache = [];

    /**
     * Resolve the custom caster class for a given key.
     */
    protected function resolveCasterClass(string $key): CastsAttributes|CastsInboundAttributes
    {
        $castType = $this->getCasts()[$key];
        if ($caster = static::$casterCache[static::class][$castType] ?? null) {
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
            return static::$casterCache[static::class][$castType] = $castClass;
        }

        return static::$casterCache[static::class][$castType] = new $castClass(...$arguments);
    }

    /**
     * Get the casts array.
     */
    public function getCasts(): array
    {
        if (! is_null($cache = static::$castsCache[static::class] ?? null)) {
            return $cache;
        }

        if ($this->getIncrementing()) {
            return static::$castsCache[static::class] = array_merge([$this->getKeyName() => $this->getKeyType()], $this->casts, $this->casts());
        }

        return static::$castsCache[static::class] = $this->casts;
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
