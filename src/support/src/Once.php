<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Context\Context;
use WeakMap;

class Once
{
    /**
     * The context key for the current once instance.
     */
    protected const INSTANCE_CONTEXT_KEY = '__support.once.instance';

    /**
     * The context key for the once enabled flag.
     */
    protected const ENABLED_CONTEXT_KEY = '__support.once.enabled';

    /**
     * Create a new once instance.
     *
     * @param WeakMap<object, array<string, mixed>> $values
     */
    protected function __construct(protected WeakMap $values)
    {
    }

    /**
     * Create a new once instance.
     */
    public static function instance(): static
    {
        return Context::getOrSet(self::INSTANCE_CONTEXT_KEY, fn () => new static(new WeakMap()));
    }

    /**
     * Get the value of the given onceable.
     */
    public function value(Onceable $onceable): mixed
    {
        if (Context::get(self::ENABLED_CONTEXT_KEY, true) !== true) {
            return call_user_func($onceable->callable);
        }

        $object = $onceable->object ?: $this;

        $hash = $onceable->hash;

        if (! isset($this->values[$object])) {
            $this->values[$object] = [];
        }

        if (array_key_exists($hash, $this->values[$object])) {
            return $this->values[$object][$hash];
        }

        return $this->values[$object][$hash] = call_user_func($onceable->callable);
    }

    /**
     * Re-enable the once instance if it was disabled.
     */
    public static function enable(): void
    {
        Context::set(self::ENABLED_CONTEXT_KEY, true);
    }

    /**
     * Disable the once instance.
     */
    public static function disable(): void
    {
        Context::set(self::ENABLED_CONTEXT_KEY, false);
    }

    /**
     * Flush the once instance.
     */
    public static function flush(): void
    {
        Context::destroy(self::INSTANCE_CONTEXT_KEY);
    }
}
