<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Partials;

use Closure;
use InvalidArgumentException;

class PartialsDefinition
{
    /** @var list<PartialDefinition> */
    protected array $includes = [];

    /** @var list<PartialDefinition> */
    protected array $excludes = [];

    /** @var list<PartialDefinition> */
    protected array $only = [];

    /** @var list<PartialDefinition> */
    protected array $except = [];

    /**
     * Determine whether no partial definitions are registered.
     */
    public function isEmpty(): bool
    {
        return $this->includes === []
            && $this->excludes === []
            && $this->only === []
            && $this->except === [];
    }

    /**
     * Add a partial definition.
     */
    public function add(
        string $type,
        string $path,
        bool $permanent = false,
        ?Closure $condition = null,
    ): void {
        $definitions = &$this->definitions($type);
        $definitions[] = new PartialDefinition($path, $permanent, $condition);
    }

    /**
     * Add class-owned permanent definitions.
     *
     * @param array<array-key, bool|Closure|string> $definitions
     */
    public function addDefaults(string $type, array $definitions): void
    {
        foreach ($definitions as $key => $definition) {
            if (is_string($definition)) {
                $this->add($type, $definition, permanent: true);

                continue;
            }

            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    "Conditional {$type} partial definitions require a string path key.",
                );
            }

            if ($definition === false) {
                continue;
            }

            $this->add(
                $type,
                $key,
                permanent: true,
                condition: $definition instanceof Closure ? $definition : null,
            );
        }
    }

    /**
     * Add definitions resolved by an enclosing data object.
     *
     * @param array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>} $definitions
     */
    public function addResolved(array $definitions): void
    {
        foreach ($definitions as $type => $resolved) {
            foreach ($resolved as $definition) {
                $this->add($type, $definition->path, $definition->permanent);
            }
        }
    }

    /**
     * Resolve active paths and optionally consume temporary definitions.
     *
     * @return array{include: list<PartialDefinition>, exclude: list<PartialDefinition>, only: list<PartialDefinition>, except: list<PartialDefinition>}
     */
    public function resolve(object $data, bool $consumeTemporary = false): array
    {
        return [
            'include' => $this->resolveType('include', $data, $consumeTemporary),
            'exclude' => $this->resolveType('exclude', $data, $consumeTemporary),
            'only' => $this->resolveType('only', $data, $consumeTemporary),
            'except' => $this->resolveType('except', $data, $consumeTemporary),
        ];
    }

    /**
     * Resolve one definition group.
     *
     * @return list<PartialDefinition>
     */
    protected function resolveType(
        string $type,
        object $data,
        bool $consumeTemporary,
    ): array {
        $definitions = &$this->definitions($type);
        $resolved = [];
        $retained = [];

        foreach ($definitions as $definition) {
            if ($definition->applies($data)) {
                $resolved[] = $definition;
            }

            if (! $consumeTemporary || $definition->permanent) {
                $retained[] = $definition;
            }
        }

        if ($consumeTemporary) {
            $definitions = $retained;
        }

        return $resolved;
    }

    /**
     * Get a mutable definition group.
     *
     * @return list<PartialDefinition>
     */
    protected function &definitions(string $type): array
    {
        switch ($type) {
            case 'include':
                return $this->includes;
            case 'exclude':
                return $this->excludes;
            case 'only':
                return $this->only;
            case 'except':
                return $this->except;
            default:
                throw new InvalidArgumentException("Unknown partial type [{$type}].");
        }
    }
}
