<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use InvalidArgumentException;

final readonly class Schema
{
    /** @var array<array-key, array<string, Table>> */
    private array $tablesByName;

    /** @var array<array-key, array<string, View>> */
    private array $viewsByName;

    /** @var array<array-key, array<string, EnumType>> */
    private array $enumsByName;

    /** @var array<array-key, array<string, Domain>> */
    private array $domainsByName;

    private bool $complete;

    /**
     * Create a schema snapshot with indexed definitions.
     *
     * @param list<Table> $tables
     * @param list<View> $views
     * @param list<EnumType> $enums
     * @param list<Domain> $domains
     * @param list<Issue> $issues
     */
    public function __construct(
        public array $tables = [],
        public array $views = [],
        public array $enums = [],
        public array $domains = [],
        public array $issues = [],
    ) {
        $this->tablesByName = $this->indexDefinitions($tables);
        $this->viewsByName = $this->indexDefinitions($views);
        $this->enumsByName = $this->indexDefinitions($enums);
        $this->domainsByName = $this->indexDefinitions($domains);
        $this->complete = $issues === [] && array_all($tables, static fn (Table $table): bool => $table->isComplete());
    }

    /**
     * Find a table by name, requiring an unqualified name to be unambiguous.
     */
    public function table(string $name, ?string $schema = null): ?Table
    {
        return $this->findDefinition($this->tablesByName, $name, $schema);
    }

    /**
     * Find a view by name, requiring an unqualified name to be unambiguous.
     */
    public function view(string $name, ?string $schema = null): ?View
    {
        return $this->findDefinition($this->viewsByName, $name, $schema);
    }

    /**
     * Find an enum type by name, requiring an unqualified name to be unambiguous.
     */
    public function enum(string $name, ?string $schema = null): ?EnumType
    {
        return $this->findDefinition($this->enumsByName, $name, $schema);
    }

    /**
     * Find a domain by name, requiring an unqualified name to be unambiguous.
     */
    public function domain(string $name, ?string $schema = null): ?Domain
    {
        return $this->findDefinition($this->domainsByName, $name, $schema);
    }

    /**
     * Determine whether all represented schema and table facts are complete.
     */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    /**
     * Index definitions without conflating qualified or quoted names.
     *
     * @template T of Table|View|EnumType|Domain
     * @param list<T> $definitions
     * @return array<array-key, array<string, T>>
     */
    private function indexDefinitions(array $definitions): array
    {
        $index = [];

        foreach ($definitions as $definition) {
            $schema = $definition->schema === null ? '' : 's:' . $definition->schema;
            $index[$definition->name][$schema] = $definition;
        }

        return $index;
    }

    /**
     * Resolve an exact identity or a unique unqualified definition.
     *
     * @template T of Table|View|EnumType|Domain
     * @param array<array-key, array<string, T>> $index
     * @return null|T
     */
    private function findDefinition(array $index, string $name, ?string $schema): Table|View|EnumType|Domain|null
    {
        $matches = $index[$name] ?? [];

        if ($schema !== null) {
            return $matches['s:' . $schema] ?? null;
        }

        if (count($matches) > 1) {
            $schemas = array_map(
                static fn (Table|View|EnumType|Domain $definition): string => $definition->schema === null
                    ? '(unqualified)'
                    : "'{$definition->schema}'",
                $matches,
            );

            throw new InvalidArgumentException("The name '{$name}' is ambiguous across schemas: " . implode(', ', $schemas) . '.');
        }

        return $matches[array_key_first($matches)] ?? null;
    }
}
