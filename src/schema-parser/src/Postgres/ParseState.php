<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Postgres;

use Hypervel\SchemaParser\CheckConstraint;
use Hypervel\SchemaParser\ColumnType;
use Hypervel\SchemaParser\Domain;
use Hypervel\SchemaParser\EnumType;
use Hypervel\SchemaParser\ForeignKey;
use Hypervel\SchemaParser\Generation;
use Hypervel\SchemaParser\Identity;
use Hypervel\SchemaParser\Index;
use Hypervel\SchemaParser\Issue;
use Hypervel\SchemaParser\TableReference;
use Hypervel\SchemaParser\View;
use sad_spirit\pg_builder\Lexer;

/**
 * Mutable definitions belong to one parse and are frozen only after dump statements are applied.
 *
 * @internal
 *
 * @phpstan-type ColumnDefinition array{name: string, type: ColumnType, nullable: bool, noInheritNotNull: bool, unvalidatedNotNull: bool, default: ?string, autoIncrement: bool, identity: ?Identity, generation: ?Generation, collation: ?string, comment: ?string}
 * @phpstan-type TableDefinition array{name: string, schema: ?string, columns: array<array-key, ColumnDefinition>, indexes: list<Index>, foreignKeys: list<ForeignKey>, checks: list<CheckConstraint>, comment: ?string, parents: list<TableReference>, partitionOf: ?TableReference, partitionBounds: ?string, issues: list<Issue>}
 * @phpstan-type DomainDefinition array{name: string, schema: ?string, type: ColumnType, nullable: bool, default: ?string, checks: list<CheckConstraint>}
 */
class ParseState
{
    /** @var array<string, TableDefinition> */
    public array $tables = [];

    /** @var array<array-key, array<string, true>> */
    public array $tableNames = [];

    /** @var array<string, array<string, true>> */
    public array $children = [];

    /** @var array<string, EnumType> */
    public array $enums = [];

    /** @var array<string, DomainDefinition> */
    public array $domains = [];

    /** @var array<array-key, array<string, true>> */
    public array $domainNames = [];

    /** @var array<string, Domain> */
    public array $resolvedDomains = [];

    /** @var array<string, View> */
    public array $views = [];

    /** @var array<array-key, array<string, true>> */
    public array $viewNames = [];

    /** @var list<Issue> */
    public array $issues = [];

    public int $offset = 0;

    public int $diagnosticOffset = 0;

    public int $diagnosticLine = 1;

    public bool $standardConformingStrings = true;

    /**
     * Create isolated readers and definition stores for one input.
     */
    public function __construct(
        public readonly string $sql,
        public readonly TypeNameResolver $types = new TypeNameResolver,
        public Lexer $literalLexer = new Lexer,
    ) {
    }
}
