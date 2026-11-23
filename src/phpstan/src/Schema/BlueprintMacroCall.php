<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Schema;

use Closure;

class BlueprintMacroCall
{
    /**
     * @internal
     * @var list<array{name: ?string}|array{type: string, name: ?string, nullable: bool, unsigned: bool}>
     */
    public private(set) array $changes = [];

    /**
     * Create the call seen by a Blueprint macro extension.
     *
     * @internal
     * @param Closure(int, string, ?string): ?string $resolveName
     * @param Closure(string): ?bool $hasColumn
     */
    public function __construct(
        private bool $creating,
        private Closure $resolveName,
        private Closure $hasColumn,
    ) {
    }

    /**
     * Read a string argument by position or parameter name.
     */
    public function name(int $position, string $parameter, ?string $default = null): ?string
    {
        return ($this->resolveName)($position, $parameter, $default);
    }

    /**
     * Determine whether the blueprint is creating a table.
     */
    public function creating(): bool
    {
        return $this->creating;
    }

    /**
     * Determine whether the table already has a column, or return null when its presence is unknown.
     */
    public function hasColumn(string $name): ?bool
    {
        return ($this->hasColumn)($name);
    }

    /**
     * Add a column of the given Blueprint type.
     */
    public function addColumn(string $type, ?string $name, bool $nullable = false, bool $unsigned = false): void
    {
        $this->changes[] = ['type' => $type, 'name' => $name, 'nullable' => $nullable, 'unsigned' => $unsigned];
    }

    /**
     * Drop a column.
     */
    public function dropColumn(?string $name): void
    {
        $this->changes[] = ['name' => $name];
    }
}
