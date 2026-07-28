<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use InvalidArgumentException;
use LogicException;

final class SerializableClassPolicy
{
    /**
     * The class resolvers registered during provider boot.
     *
     * @var list<Closure(): array<array-key, class-string>>
     */
    private array $resolvers = [];

    /**
     * The finalized class policy.
     *
     * @var null|false|list<class-string>
     */
    private array|false|null $resolvedClasses = null;

    /**
     * Whether the policy has been finalized for this process.
     */
    private bool $finalized = false;

    /**
     * Create a serializable class policy.
     *
     * @param null|(Closure(): (null|array|bool)) $configuredClassesResolver
     */
    public function __construct(
        private ?Closure $configuredClassesResolver = null,
    ) {
    }

    /**
     * Register classes that PHP may unserialize.
     *
     * Boot-only. Contributions are held for the process-wide policy and cannot
     * change after finalization.
     *
     * @param Closure(): array<array-key, class-string> $resolver
     *
     * @throws LogicException
     */
    public function allowUsing(Closure $resolver): void
    {
        if ($this->finalized) {
            throw new LogicException(
                'Serializable cache classes must be declared from a service provider boot() method before the cache policy is finalized during process startup.'
            );
        }

        $this->resolvers[] = $resolver;
    }

    /**
     * Finalize the serializable class policy.
     *
     * Boot-only. The result is frozen for every subsequent cache read in the
     * process.
     *
     * @internal
     */
    public function finalize(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->resolvedClasses = $this->resolve();
        $this->finalized = true;
        $this->configuredClassesResolver = null;
        $this->resolvers = [];
    }

    /**
     * Unserialize a cached value through the effective policy.
     */
    public function unserialize(string $value): mixed
    {
        $classes = $this->finalized
            ? $this->resolvedClasses
            : $this->resolve();

        return $classes === null
            ? unserialize($value)
            : unserialize($value, ['allowed_classes' => $classes]);
    }

    /**
     * Resolve the effective serializable class policy.
     *
     * @return null|false|list<class-string>
     */
    private function resolve(): array|false|null
    {
        if ($this->configuredClassesResolver === null) {
            return null;
        }

        $configured = ($this->configuredClassesResolver)();

        if ($configured === null || $configured === true) {
            return null;
        }

        if ($configured !== false && ! is_array($configured)) {
            throw new InvalidArgumentException(
                'The cache.serializable_classes configuration must be null, true, false, or an array of class-strings.'
            );
        }

        $classes = $configured === false
            ? []
            : $this->normalizeClasses($configured, 'cache.serializable_classes configuration');

        foreach ($this->resolvers as $index => $resolver) {
            $classes = [
                ...$classes,
                ...$this->normalizeClasses(
                    $resolver(),
                    "serializable class resolver [{$index}]",
                ),
            ];
        }

        if ($configured === false && $classes === []) {
            return false;
        }

        return array_values(array_unique($classes));
    }

    /**
     * Validate and normalize a class list.
     *
     * @return list<class-string>
     */
    private function normalizeClasses(mixed $classes, string $source): array
    {
        if (! is_array($classes)) {
            throw new InvalidArgumentException(
                "The {$source} must return an array of class-strings; " . get_debug_type($classes) . ' returned.'
            );
        }

        foreach ($classes as $key => $class) {
            if (! is_string($class)) {
                throw new InvalidArgumentException(
                    "The {$source} entry [{$key}] must be a class-string; " . get_debug_type($class) . ' given.'
                );
            }
        }

        /** @var list<class-string> $normalized */
        $normalized = array_values($classes);

        return $normalized;
    }
}
