<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Parsing\ColumnDefinition;
use Hypervel\SchemaParser\Parsing\MySqlLexer;
use Hypervel\SchemaParser\Parsing\ParseState;
use Hypervel\SchemaParser\Parsing\TableDefinition;
use Hypervel\SchemaParser\Parsing\TokenStream;

class MariaDbParser extends MySqlParser
{
    /** The kinds of index that CREATE INDEX can name before its INDEX keyword. */
    protected const array INDEX_KINDS = [
        'UNIQUE' => true,
        'FULLTEXT' => true,
        'SPATIAL' => true,
        'VECTOR' => true,
    ];

    /**
     * Create the lexer for one input, reading MariaDB executable comments as SQL.
     */
    protected function createLexer(string $sql): MySqlLexer
    {
        return new MySqlLexer($sql, mariaDb: true);
    }

    /**
     * Report system versioning, which adds implicit columns and temporal rules, before the rest of a table's tail.
     */
    protected function parseTableTail(TokenStream $tokens, ParseState $state, TableDefinition $table, bool $columns): void
    {
        if ($tokens->accept('WITH', 'SYSTEM', 'VERSIONING')) {
            $table->issues[] = $state->issue('System-versioned tables can have implicit columns and temporal rules not represented by the dump.');
        }

        parent::parseTableTail($tokens, $state, $table, $columns);
    }

    /**
     * Determine whether a table body entry is a constraint, index or period rather than a column.
     */
    protected function isTableConstraint(TokenStream $tokens): bool
    {
        return parent::isTableConstraint($tokens)
            || $tokens->is('PERIOD', 'FOR')
            // Only the compound forms declare a vector index, so columns can still be named vector.
            || $tokens->is('VECTOR', 'KEY')
            || $tokens->is('VECTOR', 'INDEX');
    }

    /**
     * Read one column clause, returning false when it is not supported.
     */
    protected function parseColumnConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ColumnDefinition $column, ?string $name): bool
    {
        // Row start and end columns hold the system period, so they have no generated expression.
        if ($tokens->accept('GENERATED', 'ALWAYS', 'AS', 'ROW') || $tokens->accept('AS', 'ROW')) {
            if (! $tokens->accept('START') && ! $tokens->accept('END')) {
                throw $tokens->error('Expected ROW START or ROW END');
            }

            return true;
        }

        // Only a system-versioned table, which already has an issue, can exclude a column from versioning.
        if ($tokens->accept('WITHOUT', 'SYSTEM', 'VERSIONING')) {
            return true;
        }

        return parent::parseColumnConstraint($tokens, $state, $table, $column, $name);
    }

    /**
     * Read a generated column's expression and kind, which PERSISTENT also declares stored.
     */
    protected function parseGeneration(TokenStream $tokens): Generation
    {
        $generation = parent::parseGeneration($tokens);

        return $generation->type === GenerationType::Virtual && $tokens->accept('PERSISTENT')
            ? new Generation(GenerationType::Stored, $generation->expression)
            : $generation;
    }

    /**
     * Read a table constraint, index or period, or a column constraint on the given column, returning false when it is not supported.
     */
    protected function parseConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name): bool
    {
        if ($column === null && $tokens->accept('PERIOD', 'FOR')) {
            // The system period is reported with the table's system versioning.
            if (! $tokens->accept('SYSTEM_TIME')) {
                $this->parseName($tokens, 'a period name');
                $table->issues[] = $state->issue('Application-time period constraints are not represented.');
            }

            $this->parseNameList($tokens, 'period column');

            return true;
        }

        if ($column === null && ($tokens->accept('VECTOR', 'KEY') || $tokens->accept('VECTOR', 'INDEX'))) {
            $this->parseTableIndex($tokens, $state, $table, null, 'vector');

            return true;
        }

        // Every supported MariaDB version creates a foreign key from a column's REFERENCES clause.
        if ($column !== null && $tokens->accept('REFERENCES')) {
            $this->parseReferences($tokens, $state, $table, $name, [$column]);

            return true;
        }

        return parent::parseConstraint($tokens, $state, $table, $column, $name);
    }

    /**
     * Read an index key, keeping an application-time period key part as its source text.
     */
    protected function parseIndexColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): IndexColumn
    {
        $start = $tokens->offset();
        $column = parent::parseIndexColumn($tokens, $state, $table);

        return $column->name !== null && $tokens->accept('WITHOUT', 'OVERLAPS')
            ? new IndexColumn(null, $tokens->spanFrom($start))
            : $column;
    }

    /**
     * Name a foreign key by its constraint name, or else by its index name as MariaDB does.
     */
    protected function foreignKeyName(?string $constraintName, ?string $indexName): ?string
    {
        return $constraintName ?? $indexName;
    }
}
