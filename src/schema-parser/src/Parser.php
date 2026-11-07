<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Contracts\Parser as ParserContract;
use Hypervel\SchemaParser\Parsing\ColumnDefinition;
use Hypervel\SchemaParser\Parsing\Lexer;
use Hypervel\SchemaParser\Parsing\ParseState;
use Hypervel\SchemaParser\Parsing\TableDefinition;
use Hypervel\SchemaParser\Parsing\TokenStream;
use Hypervel\SchemaParser\Parsing\TokenType;

/**
 * Read a schema dump statement by statement into immutable results.
 *
 * Parsers hold no state. Each parse creates its own lexer and state, which
 * the grammar methods receive the way schema grammars receive a blueprint.
 *
 * @template TLexer of Lexer
 */
abstract class Parser implements ParserContract
{
    /** The texts of the punctuation and terminator that end a list entry. */
    private const array ENTRY_ENDS = [',' => true, ')' => true, '' => true];

    /** Keywords that end a column type or default by beginning the next column clause. */
    protected const array COLUMN_CLAUSES = [
        'CONSTRAINT' => true,
        'NOT' => true,
        'NULL' => true,
        'DEFAULT' => true,
        'CHECK' => true,
        'PRIMARY' => true,
        'UNIQUE' => true,
        'COLLATE' => true,
        'REFERENCES' => true,
        'GENERATED' => true,
    ];

    /**
     * Parse a SQL schema dump.
     */
    public function parse(string $sql): Schema
    {
        $lexer = $this->createLexer($sql);
        $state = $this->createState($sql);

        while (($tokens = $lexer->nextStatement()) !== null) {
            $state->offset = $tokens->start;
            $this->parseStatement($tokens, $state);
            $tokens->skipRest();
        }

        return $this->freeze($state);
    }

    /**
     * Create the lexer for one input.
     *
     * @return TLexer
     */
    abstract protected function createLexer(string $sql): Lexer;

    /**
     * Create the state of one parse.
     */
    protected function createState(string $sql): ParseState
    {
        return new ParseState($sql);
    }

    /**
     * Read the statement's represented facts, leaving any unread remainder to be skipped.
     *
     * @param TokenStream<TLexer> $tokens
     */
    abstract protected function parseStatement(TokenStream $tokens, ParseState $state): void;

    /**
     * Read a column type.
     */
    abstract protected function parseType(TokenStream $tokens, ParseState $state): ColumnType;

    /**
     * Read an identifier.
     */
    protected function parseName(TokenStream $tokens, string $description): string
    {
        return $tokens->acceptName() ?? throw $tokens->error("Expected {$description}");
    }

    /**
     * Read an optionally schema-qualified name.
     */
    protected function parseQualifiedName(TokenStream $tokens, string $description): TableReference
    {
        $name = $this->parseName($tokens, $description);

        return $tokens->acceptSymbol('.')
            ? new TableReference($this->parseName($tokens, $description), $name)
            : new TableReference($name);
    }

    /**
     * Read the name of a table or view, which belongs to the default schema when unqualified.
     */
    protected function parseRelationName(TokenStream $tokens, ParseState $state, string $description): TableReference
    {
        $reference = $this->parseQualifiedName($tokens, $description);

        return $reference->schema === null && $state->defaultSchema !== null
            ? new TableReference($reference->name, $state->defaultSchema)
            : $reference;
    }

    /**
     * Read a parenthesized list of identifiers.
     *
     * @return list<string>
     */
    protected function parseNameList(TokenStream $tokens, string $description): array
    {
        $tokens->expectSymbol('(', "Expected a {$description} list");
        $names = [];

        do {
            $names[] = $this->parseName($tokens, "a {$description} name");
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', "Expected the end of the {$description} list");

        return $names;
    }

    /**
     * Read a collation name, keeping its qualification and quoting as written.
     */
    protected function parseCollation(TokenStream $tokens): string
    {
        $start = $tokens->offset();
        $this->parseQualifiedName($tokens, 'a collation');

        return $tokens->spanFrom($start);
    }

    /**
     * Read a parenthesized group, returning its source text with the brackets.
     */
    protected function parseGroup(TokenStream $tokens): string
    {
        $start = $tokens->offset();

        if (! $tokens->isSymbol('(')) {
            throw $tokens->error('Expected a parenthesized expression');
        }

        $tokens->skipGroup();

        return $tokens->spanFrom($start);
    }

    /**
     * Read an expression that ends before a clause keyword or the end of its list entry.
     *
     * @param array<string, true> $clauses
     */
    protected function parseExpression(TokenStream $tokens, array $clauses, string $description): string
    {
        $start = $tokens->offset();

        if (! $tokens->skipExpression($clauses)) {
            throw $tokens->error("Expected {$description}");
        }

        return $tokens->spanFrom($start);
    }

    /**
     * Determine whether the current word begins one of the given clauses rather than naming a schema.
     *
     * @param array<string, true> $clauses
     */
    protected function atClause(TokenStream $tokens, array $clauses): bool
    {
        return isset($clauses[$tokens->text()]) && ! $tokens->isSymbol('.', 1);
    }

    /**
     * Read a string literal that forms a complete value, or return null without consuming anything.
     */
    protected function parseLiteral(TokenStream $tokens): ?string
    {
        $type = $tokens->type();

        if ($type !== TokenType::String && $type !== TokenType::EscapedString) {
            return null;
        }

        if ($tokens->type(1) !== TokenType::End && ! $tokens->isSymbol(',', 1) && ! $tokens->isSymbol(')', 1)) {
            return null;
        }

        $value = $tokens->value();
        $tokens->next();

        return $value;
    }

    /**
     * Determine whether the current list entry, such as a column definition, has ended.
     *
     * @phpstan-impure
     */
    protected function atEntryEnd(TokenStream $tokens): bool
    {
        return isset(self::ENTRY_ENDS[$tokens->text()]);
    }

    /**
     * Skip the rest of the current list entry.
     */
    protected function skipEntry(TokenStream $tokens): void
    {
        while (! $this->atEntryEnd($tokens)) {
            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
            } else {
                $tokens->next();
            }
        }
    }

    /**
     * Read the parenthesized columns and constraints of a table.
     */
    protected function parseTableBody(TokenStream $tokens, ParseState $state, TableDefinition $table): void
    {
        $tokens->expectSymbol('(', 'Expected a table definition');

        if ($tokens->acceptSymbol(')')) {
            return;
        }

        do {
            if ($tokens->atEnd()) {
                throw $tokens->error('Unterminated table definition');
            }

            if ($this->isTableConstraint($tokens)) {
                $this->parseTableConstraint($tokens, $state, $table);
            } else {
                $this->parseColumn($tokens, $state, $table);
            }
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Unterminated table definition');
    }

    /**
     * Determine whether a table body entry is a constraint rather than a column.
     */
    protected function isTableConstraint(TokenStream $tokens): bool
    {
        return in_array($tokens->keyword(), ['CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK'], true);
    }

    /**
     * Read the name given by a CONSTRAINT keyword, if any.
     */
    protected function parseConstraintName(TokenStream $tokens): ?string
    {
        return $tokens->accept('CONSTRAINT') ? $this->parseName($tokens, 'a constraint name') : null;
    }

    /**
     * Read a table constraint, keeping an unsupported one as a table issue.
     */
    protected function parseTableConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table): void
    {
        $name = $this->parseConstraintName($tokens);

        if (! $this->parseConstraint($tokens, $state, $table, null, $name)) {
            $table->issues[] = $state->issue('A table constraint could not be represented.');
        } elseif ($this->atEntryEnd($tokens)) {
            return;
        } else {
            $table->issues[] = $state->issue('The table constraint has unsupported trailing syntax.');
        }

        $this->skipEntry($tokens);
    }

    /**
     * Read a table constraint, or a column constraint on the given column, returning false when it is not supported.
     */
    abstract protected function parseConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name): bool;

    /**
     * Read a column definition into the table.
     */
    protected function parseColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): ColumnDefinition
    {
        $name = $this->parseName($tokens, 'a column name');
        $column = new ColumnDefinition($name, $this->parseType($tokens, $state));
        $table->columns[$name] = $column;

        while (! $this->atEntryEnd($tokens)) {
            $constraint = $this->parseConstraintName($tokens);

            if (! $this->parseColumnConstraint($tokens, $state, $table, $column, $constraint)) {
                $table->issues[] = $state->issue("Column [{$name}] has unsupported declaration syntax.");
                $this->skipEntry($tokens);
            }
        }

        return $column;
    }

    /**
     * Read one column clause, returning false when it is not supported.
     */
    protected function parseColumnConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ColumnDefinition $column, ?string $name): bool
    {
        switch ($tokens->text()) {
            case 'NOT':
                if ($tokens->accept('NOT', 'NULL')) {
                    $column->nullable = false;

                    return true;
                }

                break;
            case 'NULL':
                $tokens->next();

                return true;
            case 'DEFAULT':
                $tokens->next();
                $column->default = $this->parseExpression($tokens, static::COLUMN_CLAUSES, 'a default expression');

                return true;
            case 'COLLATE':
                $tokens->next();
                $column->collation = $this->parseCollation($tokens);

                return true;
            case 'GENERATED':
                if ($tokens->accept('GENERATED', 'ALWAYS', 'AS')) {
                    $column->generation = $this->parseGeneration($tokens);

                    return true;
                }

                break;
        }

        return $this->parseConstraint($tokens, $state, $table, $column->name, $name);
    }

    /**
     * Read a generated column's parenthesized expression and kind, which is virtual unless stored.
     */
    protected function parseGeneration(TokenStream $tokens): Generation
    {
        $expression = $this->parseGroup($tokens);

        if ($tokens->accept('STORED')) {
            return new Generation(GenerationType::Stored, $expression);
        }

        $tokens->accept('VIRTUAL');

        return new Generation(GenerationType::Virtual, $expression);
    }

    /**
     * Make the columns of a primary key non-null on a table.
     */
    protected function markPrimaryKeyColumnsNotNull(Index $index, TableDefinition $table): void
    {
        foreach ($index->columns as $key) {
            $column = $key->name === null ? null : $this->findColumn($table, $key->name);

            if ($column !== null) {
                $column->nullable = false;
            }
        }
    }

    /**
     * Find a table's column by the name a key or constraint gives it.
     */
    protected function findColumn(TableDefinition $table, string $name): ?ColumnDefinition
    {
        return $table->columns[$name] ?? null;
    }

    /**
     * Read a parenthesized list of index keys.
     *
     * @return list<IndexColumn>
     */
    protected function parseIndexColumns(TokenStream $tokens, ParseState $state, TableDefinition $table): array
    {
        $tokens->expectSymbol('(', 'Expected an index key list');
        $columns = [];

        do {
            $columns[] = $this->parseIndexColumn($tokens, $state, $table);
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Expected the end of the index key list');

        return $columns;
    }

    /**
     * Read an index key without confusing an expression with a column name.
     */
    abstract protected function parseIndexColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): IndexColumn;

    /**
     * Read a foreign key's match mode and referential actions, which can come in any order.
     *
     * @return array{ForeignKeyMatch, ReferentialAction, ReferentialAction} the match mode, then the update and delete actions
     */
    protected function parseForeignKeyRules(TokenStream $tokens, ParseState $state, TableDefinition $table): array
    {
        $match = ForeignKeyMatch::Simple;
        $onUpdate = $onDelete = ReferentialAction::NoAction;

        while (true) {
            if ($tokens->accept('MATCH')) {
                $match = match ($tokens->keyword()) {
                    'FULL' => ForeignKeyMatch::Full,
                    'PARTIAL' => ForeignKeyMatch::Partial,
                    'SIMPLE' => ForeignKeyMatch::Simple,
                    default => throw $tokens->error('Expected a foreign key match type'),
                };
                $tokens->next();
            } elseif ($tokens->accept('ON', 'UPDATE')) {
                $onUpdate = $this->parseReferentialAction($tokens);
            } elseif ($tokens->accept('ON', 'DELETE')) {
                $onDelete = $this->parseReferentialAction($tokens);
            } else {
                return [$match, $onUpdate, $onDelete];
            }

            // PostgreSQL can limit a SET NULL or SET DEFAULT action to some of the key's columns.
            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
                $table->issues[] = $state->issue('A foreign key action applies to only some of its columns.');
            }
        }
    }

    /**
     * Read a referential action.
     */
    protected function parseReferentialAction(TokenStream $tokens): ReferentialAction
    {
        return match (true) {
            $tokens->accept('CASCADE') => ReferentialAction::Cascade,
            $tokens->accept('RESTRICT') => ReferentialAction::Restrict,
            $tokens->accept('NO', 'ACTION') => ReferentialAction::NoAction,
            $tokens->accept('SET', 'NULL') => ReferentialAction::SetNull,
            $tokens->accept('SET', 'DEFAULT') => ReferentialAction::SetDefault,
            default => throw $tokens->error('Expected a referential action'),
        };
    }

    /**
     * Read a view from its name, without inferring the types its query produces.
     */
    protected function parseCreateView(TokenStream $tokens, ParseState $state, bool $materialized = false): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $reference = $this->parseRelationName($tokens, $state, 'a view name');

        if ($this->isTemporary($reference) || ($conditional && $this->relationExists($state, $reference->schema, $reference->name))) {
            return;
        }

        // A column list and view options can precede the query.
        while (! $tokens->atEnd() && ! $tokens->is('AS')) {
            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
            } else {
                $tokens->next();
            }
        }

        if (! $tokens->accept('AS')) {
            throw $tokens->error('Expected a view query');
        }

        $state->addView(new View($reference->name, $this->parseViewQuery($tokens), $reference->schema, $materialized));
    }

    /**
     * Determine whether a name belongs to the session's temporary schema.
     */
    protected function isTemporary(TableReference $reference): bool
    {
        return false;
    }

    /**
     * Determine whether a known relation already has the given name.
     */
    protected function relationExists(ParseState $state, ?string $schema, string $name): bool
    {
        $key = $state->key($schema, $name);

        return isset($state->tables[$key]) || isset($state->views[$key]);
    }

    /**
     * Read a view query, excluding a trailing view option.
     */
    protected function parseViewQuery(TokenStream $tokens): string
    {
        $start = $tokens->offset();

        while (! $tokens->atEnd() && ! ($tokens->is('WITH') && $this->isViewOption($tokens))) {
            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
            } else {
                $tokens->next();
            }
        }

        if ($tokens->offset() === $start) {
            throw $tokens->error('Expected a view query');
        }

        return $tokens->spanFrom($start);
    }

    /**
     * Determine whether a WITH clause is the view's trailing check option.
     */
    protected function isViewOption(TokenStream $tokens): bool
    {
        $offset = $tokens->keyword(1) === 'LOCAL' || $tokens->keyword(1) === 'CASCADED' ? 2 : 1;

        return $tokens->keyword($offset) === 'CHECK'
            && $tokens->keyword($offset + 1) === 'OPTION'
            && $tokens->type($offset + 2) === TokenType::End;
    }

    /**
     * Freeze the parsed definitions into a schema.
     */
    protected function freeze(ParseState $state): Schema
    {
        $tables = [];

        foreach ($state->tables as $table) {
            $tables[] = $this->freezeTable($table, $state);
        }

        return new Schema($tables, array_values($state->views), array_values($state->enums), array_values($state->domains), $state->issues);
    }

    /**
     * Freeze a table after resolving foreign keys that omit their referenced columns.
     */
    protected function freezeTable(TableDefinition $table, ParseState $state): Table
    {
        $columns = [];

        foreach ($table->columns as $column) {
            $columns[] = $this->freezeColumn($column, $table, $state);
        }

        $foreignKeys = $table->foreignKeys;

        foreach ($foreignKeys as $position => $foreignKey) {
            if ($foreignKey->foreignColumns !== []) {
                continue;
            }

            $foreignColumns = $this->referencedColumns($foreignKey, $state);

            if ($foreignColumns === null) {
                $table->issues[] = new Issue("The referenced columns of foreign table [{$foreignKey->foreignTable->name}] could not be resolved.");
                continue;
            }

            $foreignKeys[$position] = new ForeignKey(
                $foreignKey->name,
                $foreignKey->columns,
                $foreignKey->foreignTable,
                $foreignColumns,
                $foreignKey->onUpdate,
                $foreignKey->onDelete,
                $foreignKey->match,
                $foreignKey->deferral,
                $foreignKey->valid,
                $foreignKey->enforced,
            );
        }

        return new Table(
            $table->name,
            $columns,
            $table->schema,
            $table->indexes,
            $foreignKeys,
            $table->checks,
            $table->comment,
            $table->collation,
            $table->engine,
            $table->parents,
            $table->partitionOf,
            $table->partitionBounds,
            $table->issues,
        );
    }

    /**
     * Get the columns a foreign key references, which default to the referenced table's primary key, or null when they cannot be resolved.
     *
     * @return null|list<string>
     */
    protected function referencedColumns(ForeignKey $foreignKey, ParseState $state): ?array
    {
        if ($foreignKey->foreignColumns !== []) {
            return $foreignKey->foreignColumns;
        }

        $target = $state->tableKey($foreignKey->foreignTable);
        $primary = $target === null ? null : array_find(
            $state->tables[$target]->indexes,
            static fn (Index $index): bool => $index->primary,
        );
        $foreignColumns = array_column($primary->columns ?? [], 'name');

        // A key part without a column name, such as a temporal one, cannot name a referenced column.
        return $foreignColumns === [] || in_array(null, $foreignColumns, true) ? null : $foreignColumns;
    }

    /**
     * Freeze a column.
     */
    protected function freezeColumn(ColumnDefinition $column, TableDefinition $table, ParseState $state): Column
    {
        return new Column(
            $column->name,
            $column->type,
            $column->nullable,
            $column->default,
            $column->autoIncrement,
            $column->identity,
            $column->generation,
            $column->onUpdate,
            $column->invisible,
            $column->collation,
            $column->comment,
        );
    }
}
