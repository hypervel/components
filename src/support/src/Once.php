<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Hypervel\Context\CoroutineContext;
use WeakMap;
use WeakReference;

class Once
{
    /**
     * The context key for the current once instance.
     */
    protected const string INSTANCE_CONTEXT_KEY = '__support.once.instance';

    /**
     * The context key for the once enabled flag.
     */
    protected const string ENABLED_CONTEXT_KEY = '__support.once.enabled';

    /**
     * Create a new once instance.
     *
     * @param WeakMap<object, array<string, array{value: mixed, objects: array<int, WeakReference<object>>}>> $values
     */
    protected function __construct(protected WeakMap $values)
    {
    }

    /**
     * Create a new once instance.
     */
    public static function instance(): static
    {
        return CoroutineContext::getOrSet(self::INSTANCE_CONTEXT_KEY, fn () => new static(new WeakMap));
    }

    /**
     * Get the value of the given onceable.
     */
    public function value(Onceable $onceable): mixed
    {
        if (CoroutineContext::get(self::ENABLED_CONTEXT_KEY, true) !== true) {
            return call_user_func($onceable->callable);
        }

        $object = $onceable->object ?: $this;

        $hash = $onceable->hash;

        if (! isset($this->values[$object])) {
            $this->values[$object] = [];
        }

        $objects = $onceable->objects();

        if (isset($this->values[$object][$hash]) && $this->usesSameObjects($this->values[$object][$hash]['objects'], $objects)) {
            return $this->values[$object][$hash]['value'];
        }

        $value = call_user_func($onceable->callable);

        $this->values[$object][$hash] = [
            'value' => $value,
            'objects' => array_map(WeakReference::create(...), $objects),
        ];

        return $value;
    }

    /**
     * Determine if the cached references still point to the given objects.
     *
     * Object hashes are based on object IDs, which PHP reuses once an object is destroyed.
     *
     * @param array<int, WeakReference<object>> $references
     * @param array<int, object> $objects
     */
    protected function usesSameObjects(array $references, array $objects): bool
    {
        foreach ($objects as $key => $object) {
            if ($references[$key]->get() !== $object) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-enable the once instance if it was disabled.
     */
    public static function enable(): void
    {
        CoroutineContext::set(self::ENABLED_CONTEXT_KEY, true);
    }

    /**
     * Disable the once instance.
     */
    public static function disable(): void
    {
        CoroutineContext::set(self::ENABLED_CONTEXT_KEY, false);
    }

    /**
     * Flush the once instance.
     */
    public static function flush(): void
    {
        CoroutineContext::forget(self::INSTANCE_CONTEXT_KEY);
    }
}
