<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Parsing\ColumnDefinition;
use Hypervel\SchemaParser\Parsing\ParseState;
use Hypervel\SchemaParser\Parsing\SQLiteLexer;
use Hypervel\SchemaParser\Parsing\TableDefinition;
use Hypervel\SchemaParser\Parsing\TokenStream;
use Hypervel\SchemaParser\Parsing\TokenType;

/**
 * @extends Parser<SQLiteLexer>
 */
class SQLiteParser extends Parser
{
    /** The database that unqualified persistent names belong to. */
    private const string MAIN = 'main';

    /** Keywords that begin a column clause, so none of them can be a type word. */
    protected const array COLUMN_CLAUSES = [
        'AS' => true,
        'CHECK' => true,
        'COLLATE' => true,
        'CONSTRAINT' => true,
        'DEFAULT' => true,
        'DEFERRABLE' => true,
        'NOT' => true,
        'NULL' => true,
        'PRIMARY' => true,
        'REFERENCES' => true,
        'UNIQUE' => true,
    ];

    /** Keywords that follow an index key; COLLATE is not one, since it can be part of the key's expression. */
    private const array KEY_CLAUSES = [
        'ASC' => true,
        'AUTOINCREMENT' => true,
        'DESC' => true,
    ];

    /** The ways ON CONFLICT can resolve a constraint violation. */
    private const array CONFLICT_RESOLUTIONS = [
        'ABORT' => true,
        'FAIL' => true,
        'IGNORE' => true,
        'REPLACE' => true,
        'ROLLBACK' => true,
    ];

    /**
     * Create the lexer for one input.
     */
    protected function createLexer(string $sql): SQLiteLexer
    {
        return new SQLiteLexer($sql);
    }

    /**
     * Create the state of one parse, in which names compare as SQLite compares them.
     */
    protected function createState(string $sql): ParseState
    {
        $state = new ParseState($sql, caseInsensitive: true);
        $state->defaultSchema = self::MAIN;

        return $state;
    }

    /**
     * Read the statement's represented facts, leaving any unread remainder to be skipped.
     */
    protected function parseStatement(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('CREATE')) {
            $this->parseCreate($tokens, $state);
        } elseif ($tokens->accept('DROP')) {
            $this->parseDrop($tokens, $state);
        } elseif ($tokens->accept('ALTER', 'TABLE')) {
            $this->parseAlterTable($tokens, $state);
        }
    }

    /**
     * Read a CREATE statement for a represented object.
     *
     * Temporary objects are declared with TEMP or TEMPORARY before their kind, so they are never read.
     */
    private function parseCreate(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('TABLE')) {
            $this->parseCreateTable($tokens, $state);
        } elseif ($tokens->accept('VIEW')) {
            $this->parseCreateView($tokens, $state);
        } elseif ($tokens->accept('INDEX')) {
            $this->parseCreateIndex($tokens, $state, false);
        } elseif ($tokens->accept('UNIQUE', 'INDEX')) {
            $this->parseCreateIndex($tokens, $state, true);
        } elseif ($tokens->accept('VIRTUAL', 'TABLE')) {
            $this->parseCreateVirtualTable($tokens, $state);
        }
    }

    /**
     * Read an identifier, which SQLite also accepts as a string literal.
     */
    protected function parseName(TokenStream $tokens, string $description): string
    {
        if (! $this->isStringName($tokens)) {
            return parent::parseName($tokens, $description);
        }

        $name = $tokens->value();
        $tokens->next();

        return $name;
    }

    /**
     * Determine whether the current token is a string literal, which SQLite reads as a name where it expects one.
     */
    private function isStringName(TokenStream $tokens): bool
    {
        // Blob literals are also strings, but begin with an X.
        return $tokens->type() === TokenType::String && $tokens->text()[0] === "'";
    }

    /**
     * Read an optionally schema-qualified name, spelling an explicit main database as unqualified names do.
     */
    protected function parseQualifiedName(TokenStream $tokens, string $description): TableReference
    {
        $reference = parent::parseQualifiedName($tokens, $description);

        return $reference->schema !== null && $reference->schema !== self::MAIN && strcasecmp($reference->schema, self::MAIN) === 0
            ? new TableReference($reference->name, self::MAIN)
            : $reference;
    }

    /**
     * Determine whether a name belongs to the session's temporary database.
     */
    protected function isTemporary(TableReference $reference): bool
    {
        return $reference->schema !== null && strcasecmp($reference->schema, 'temp') === 0;
    }

    /**
     * Read a table and its options, which decide what its primary key guarantees.
     */
    private function parseCreateTable(TokenStream $tokens, ParseState $state): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $reference = $this->parseRelationName($tokens, $state, 'a table name');

        if ($this->isTemporary($reference) || ($conditional && $this->relationExists($state, $reference->schema, $reference->name))) {
            return;
        }

        $table = new TableDefinition($reference->name, $reference->schema);

        if ($tokens->accept('AS')) {
            $table->issues[] = $state->issue('The table declaration does not contain a complete column definition.');
        } else {
            $this->parseTableBody($tokens, $state, $table);
            $this->parseTableOptions($tokens, $state, $table);
        }

        $state->addTable($table);
    }

    /**
     * Read the STRICT and WITHOUT ROWID options, then settle what the primary key guarantees.
     */
    private function parseTableOptions(TokenStream $tokens, ParseState $state, TableDefinition $table): void
    {
        $strict = $withoutRowid = false;

        while (! $tokens->atEnd()) {
            if ($tokens->accept('STRICT')) {
                $strict = true;
            } elseif ($tokens->accept('WITHOUT', 'ROWID')) {
                $withoutRowid = true;
            } elseif (! $tokens->acceptSymbol(',')) {
                $table->issues[] = $state->issue('The table has unsupported options.');

                break;
            }
        }

        $primary = array_find($table->indexes, static fn (Index $index): bool => $index->primary);

        foreach ($primary->columns ?? [] as $key) {
            $column = $key->name === null ? null : $this->findColumn($table, $key->name);

            if ($column === null) {
                continue;
            }

            // A WITHOUT ROWID table has no rowid for an INTEGER PRIMARY KEY to alias.
            if ($withoutRowid) {
                $column->autoIncrement = false;
            }

            if ($column->autoIncrement || $strict || $withoutRowid) {
                $column->nullable = false;
            }
        }
    }

    /**
     * Read a virtual table, whose columns are declared by its module.
     */
    private function parseCreateVirtualTable(TokenStream $tokens, ParseState $state): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $reference = $this->parseRelationName($tokens, $state, 'a table name');

        if ($this->isTemporary($reference) || ($conditional && $this->relationExists($state, $reference->schema, $reference->name))) {
            return;
        }

        $table = new TableDefinition($reference->name, $reference->schema);
        $table->issues[] = $state->issue('The virtual table declaration does not contain its column definitions.');
        $state->addTable($table);
    }

    /**
     * Read a column definition, whose latest constraint name applies to every constraint after it.
     */
    protected function parseColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): ColumnDefinition
    {
        $name = $this->parseName($tokens, 'a column name');
        $column = new ColumnDefinition($name, $this->parseType($tokens, $state));
        $table->columns[$name] = $column;
        $constraint = null;

        while (! $this->atEntryEnd($tokens)) {
            if ($tokens->is('CONSTRAINT')) {
                $constraint = $this->parseConstraintName($tokens);
            } elseif (! $this->parseColumnConstraint($tokens, $state, $table, $column, $constraint)) {
                $table->issues[] = $state->issue("Column [{$name}] has unsupported declaration syntax.");
                $this->skipEntry($tokens);
            }
        }

        return $column;
    }

    /**
     * Read table constraints up to the next comma, which SQLite lets follow one another under the latest constraint name.
     */
    protected function parseTableConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table): void
    {
        $name = null;

        do {
            if ($tokens->is('CONSTRAINT')) {
                $name = $this->parseConstraintName($tokens);
            } elseif (! $this->parseConstraint($tokens, $state, $table, null, $name)) {
                $table->issues[] = $state->issue('A table constraint could not be represented.');
                $this->skipEntry($tokens);
            }
        } while (! $this->atEntryEnd($tokens));
    }

    /**
     * Find a table's column by name, which SQLite compares without ASCII case.
     */
    protected function findColumn(TableDefinition $table, string $name): ?ColumnDefinition
    {
        return $table->columns[$name]
            ?? array_find($table->columns, static fn (ColumnDefinition $column): bool => strcasecmp($column->name, $name) === 0);
    }

    /**
     * Read a column type: its words in lower case, joined by single spaces, and its signed number modifiers.
     *
     * A column can have no type, which gives an empty name.
     */
    protected function parseType(TokenStream $tokens, ParseState $state): ColumnType
    {
        $words = [];

        while (true) {
            $type = $tokens->type();

            if ($type === TokenType::Word) {
                // GENERATED is a type word unless it begins GENERATED ALWAYS AS.
                if (isset(static::COLUMN_CLAUSES[$tokens->text()]) || $tokens->is('GENERATED', 'ALWAYS')) {
                    break;
                }

                $words[] = strtolower($tokens->text());
            } elseif ($type === TokenType::QuotedIdentifier || $this->isStringName($tokens)) {
                $words[] = strtolower($tokens->value());
            } else {
                break;
            }

            $tokens->next();
        }

        $modifiers = $words !== [] && $tokens->isSymbol('(') ? $this->parseTypeModifiers($tokens) : [];

        return new ColumnType(implode(' ', $words), modifiers: $modifiers);
    }

    /**
     * Read the one or two signed numbers that modify a type, as they are written.
     *
     * @return list<string>
     */
    private function parseTypeModifiers(TokenStream $tokens): array
    {
        $tokens->expectSymbol('(', 'Expected type modifiers');
        $modifiers = [];

        do {
            $start = $tokens->offset();

            if ($tokens->isSymbol('-') || $tokens->isSymbol('+')) {
                $tokens->next();
            }

            if ($tokens->type() !== TokenType::Number) {
                throw $tokens->error('Expected a type modifier number');
            }

            $tokens->next();
            $modifiers[] = $tokens->spanFrom($start);
        } while (count($modifiers) < 2 && $tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Expected the end of the type modifiers');

        return $modifiers;
    }

    /**
     * Read one column clause, returning false when it is not supported.
     */
    protected function parseColumnConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ColumnDefinition $column, ?string $name): bool
    {
        switch ($tokens->text()) {
            case 'NOT':
                if (! $tokens->accept('NOT', 'NULL')) {
                    break;
                }

                $column->nullable = false;
                $this->skipConflictClause($tokens);

                return true;
            case 'NULL':
                $tokens->next();
                $this->skipConflictClause($tokens);

                return true;
            case 'DEFAULT':
                $tokens->next();
                $column->default = $this->parseDefault($tokens);

                return true;
            case 'AS':
                $tokens->next();
                $column->generation = $this->parseGeneration($tokens);

                return true;
        }

        return parent::parseColumnConstraint($tokens, $state, $table, $column, $name);
    }

    /**
     * Read a default as pragma_table_info reports it.
     *
     * A default is a signed literal or a name, or a parenthesized expression
     * whose brackets are syntax rather than part of its value.
     */
    private function parseDefault(TokenStream $tokens): string
    {
        if ($tokens->isSymbol('(')) {
            return trim(substr($this->parseGroup($tokens), 1, -1), " \t\n\v\f\r");
        }

        $start = $tokens->offset();

        if ($tokens->isSymbol('-') || $tokens->isSymbol('+')) {
            $tokens->next();
        }

        $type = $tokens->type();

        if ($type === TokenType::Symbol || $type === TokenType::End) {
            throw $tokens->error('Expected a default expression');
        }

        $tokens->next();

        return $tokens->spanFrom($start);
    }

    /**
     * Read a table constraint, or a column constraint on the given column, returning false when it is not supported.
     */
    protected function parseConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name): bool
    {
        if ($tokens->accept('CHECK')) {
            $table->checks[] = new CheckConstraint($name, $this->parseGroup($tokens));
            $this->skipConflictClause($tokens);

            return true;
        }

        if ($tokens->accept('PRIMARY', 'KEY')) {
            $this->parsePrimaryKey($tokens, $state, $table, $column);

            return true;
        }

        // A key's index is named by the server, so a constraint name does not name it.
        if ($tokens->accept('UNIQUE')) {
            $columns = $column === null ? $this->parseIndexColumns($tokens, $state, $table) : [new IndexColumn($column)];
            $this->skipConflictClause($tokens);
            $table->indexes[] = new Index(null, $columns, unique: true, constraint: true);

            return true;
        }

        if ($column !== null && $tokens->accept('REFERENCES')) {
            $this->parseReferences($tokens, $state, $table, $name, [$column]);

            return true;
        }

        if ($column === null && $tokens->accept('FOREIGN', 'KEY')) {
            // SQLite reports a key's columns by their declared names.
            $columns = array_map(
                fn (string $name): string => $this->findColumn($table, $name)->name ?? $name,
                $this->parseNameList($tokens, 'foreign key column'),
            );

            if (! $tokens->accept('REFERENCES')) {
                throw $tokens->error('Expected a referenced foreign table');
            }

            $this->parseReferences($tokens, $state, $table, $name, $columns);

            return true;
        }

        if ($tokens->is('DEFERRABLE') || $tokens->is('NOT', 'DEFERRABLE')) {
            $this->parseDeferral($tokens, $table);

            return true;
        }

        if ($tokens->is('INITIALLY')) {
            throw $tokens->error('Expected DEFERRABLE before INITIALLY');
        }

        return false;
    }

    /**
     * Read a primary key, marking an INTEGER PRIMARY KEY as the rowid alias it becomes in a rowid table.
     */
    private function parsePrimaryKey(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column): void
    {
        if ($column === null) {
            $columns = $this->parseIndexColumns($tokens, $state, $table);
            $alias = count($columns) === 1 ? $columns[0]->name : null;
        } else {
            $descending = $tokens->accept('DESC');

            if (! $descending) {
                $tokens->accept('ASC');
            }

            $columns = [new IndexColumn($column, descending: $descending)];

            // A descending column key is the one INTEGER PRIMARY KEY form that does not alias the rowid.
            $alias = $descending ? null : $column;
        }

        $this->skipConflictClause($tokens);
        $tokens->accept('AUTOINCREMENT');
        $table->indexes[] = new Index(null, $columns, unique: true, primary: true, constraint: true);
        $target = $alias === null ? null : $this->findColumn($table, $alias);

        // The table's options can still rule out a rowid alias, so its guarantee is settled with them.
        if ($target !== null && $target->type->name === 'integer' && $target->type->modifiers === []) {
            $target->autoIncrement = true;
        }
    }

    /**
     * Read an index key: one of the table's columns, possibly in brackets and collations, or an expression.
     */
    protected function parseIndexColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): IndexColumn
    {
        [$name, $collation] = $this->parseKeyColumn($tokens, $table) ?? [null, null];
        $expression = null;

        if ($name === null) {
            [$expression, $collation] = $this->parseKeyExpression($tokens);
        }

        $descending = $tokens->accept('DESC');

        if (! $descending) {
            $tokens->accept('ASC');
        }

        // A table primary key can end its key list with AUTOINCREMENT.
        $tokens->accept('AUTOINCREMENT');

        return new IndexColumn($name, $expression, $descending, collation: $collation);
    }

    /**
     * Read an index key that names a column through any brackets and collations around it, or return null without consuming anything.
     *
     * @return null|array{string, ?string} the column's declared name when the table has it, and the outermost collation
     */
    private function parseKeyColumn(TokenStream $tokens, TableDefinition $table): ?array
    {
        $mark = $tokens->mark();
        $depth = 0;

        while ($tokens->acceptSymbol('(')) {
            ++$depth;
        }

        $type = $tokens->type();
        $text = $tokens->text();

        if (($type === TokenType::Word && $text !== 'NULL' && $text !== 'NOT') || $type === TokenType::QuotedIdentifier || $this->isStringName($tokens)) {
            $name = $this->parseName($tokens, 'an index key');
            $collation = null;

            // Each collation wraps what comes before it, so the last one applies.
            while (true) {
                if ($tokens->accept('COLLATE')) {
                    $collation = $this->parseCollation($tokens);
                } elseif ($depth > 0 && $tokens->acceptSymbol(')')) {
                    --$depth;
                } else {
                    break;
                }
            }

            if ($depth === 0 && $this->atKeyEnd($tokens)) {
                $column = $this->findColumn($table, $name);

                // SQLite reads an unknown TRUE or FALSE as a boolean, and an unknown double-quoted name as a string.
                if ($column !== null || ! ($type === TokenType::Word ? $text === 'TRUE' || $text === 'FALSE' : $text[0] === '"')) {
                    return [$column->name ?? $name, $collation];
                }
            }
        }

        $tokens->rewind($mark);

        return null;
    }

    /**
     * Read an index key expression, separating a trailing collation only when it applies to the whole key.
     *
     * COLLATE binds tighter than any binary operator, so it covers the key only after a single operand:
     * lower(name) COLLATE NOCASE has that collation, while id + 1 COLLATE NOCASE does not.
     *
     * @return array{string, ?string} the expression and its collation
     */
    private function parseKeyExpression(TokenStream $tokens): array
    {
        $start = $tokens->offset();
        $skippedOperand = $this->skipOperand($tokens);

        if ($skippedOperand && $tokens->is('COLLATE')) {
            $operand = $tokens->spanFrom($start);
            $collation = null;

            while ($tokens->accept('COLLATE')) {
                $collation = $this->parseCollation($tokens);
            }

            if ($this->atKeyEnd($tokens)) {
                return [$operand, $collation];
            }
        }

        // The operand may have skipped a group, which cannot be read again, so the rest of the key is read from here.
        if (! $this->atKeyEnd($tokens)) {
            $tokens->skipExpression(self::KEY_CLAUSES, $skippedOperand);
        }

        if ($tokens->offset() === $start) {
            throw $tokens->error('Expected an index key');
        }

        return [$tokens->spanFrom($start), null];
    }

    /**
     * Determine whether the current index key has ended.
     *
     * @phpstan-impure
     */
    private function atKeyEnd(TokenStream $tokens): bool
    {
        return $this->atEntryEnd($tokens) || isset(self::KEY_CLAUSES[$tokens->text()]);
    }

    /**
     * Skip a single operand: a literal, name, function call or bracketed group after any unary signs.
     */
    private function skipOperand(TokenStream $tokens): bool
    {
        while ($tokens->isSymbol('-') || $tokens->isSymbol('+') || $tokens->isSymbol('~')) {
            $tokens->next();
        }

        if ($tokens->isSymbol('(')) {
            $tokens->skipGroup();

            return true;
        }

        $type = $tokens->type();

        $text = $tokens->text();

        // NOT binds looser than COLLATE, and CASE is left whole to skipExpression(), which pairs it with its END.
        if ($type === TokenType::Symbol || $type === TokenType::End || $text === 'NOT' || $text === 'CASE') {
            return false;
        }

        $tokens->next();

        if ($tokens->isSymbol('(')) {
            $tokens->skipGroup();
        }

        return true;
    }

    /**
     * Read a foreign key's referenced table, columns and rules after its REFERENCES keyword.
     *
     * @param list<string> $columns
     */
    private function parseReferences(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name, array $columns): void
    {
        $foreignTable = $this->parseName($tokens, 'a referenced table name');
        $foreignColumns = $tokens->isSymbol('(') ? $this->parseNameList($tokens, 'referenced column') : [];
        [$match, $onUpdate, $onDelete] = $this->parseForeignKeyRules($tokens, $state, $table);

        $table->foreignKeys[] = new ForeignKey(
            $name,
            $columns,
            // A foreign key can only reference a table in its own table's database.
            new TableReference($foreignTable, $table->schema),
            $foreignColumns,
            $onUpdate,
            $onDelete,
            $match,
        );
    }

    /**
     * Read a foreign key's match mode and referential actions, which can come in any order.
     *
     * SQLite accepts any match name and an ON INSERT action, and enforces neither.
     *
     * @return array{ForeignKeyMatch, ReferentialAction, ReferentialAction} the match mode, then the update and delete actions
     */
    protected function parseForeignKeyRules(TokenStream $tokens, ParseState $state, TableDefinition $table): array
    {
        $match = ForeignKeyMatch::Simple;
        $onUpdate = $onDelete = ReferentialAction::NoAction;

        while (true) {
            if ($tokens->accept('MATCH')) {
                $mode = $this->parseName($tokens, 'a foreign key match type');
                $known = ForeignKeyMatch::tryFrom(strtolower($mode));

                if ($known === null) {
                    $table->issues[] = $state->issue("Foreign key match type [{$mode}] is not represented.");
                } else {
                    $match = $known;
                }
            } elseif ($tokens->accept('ON', 'UPDATE')) {
                $onUpdate = $this->parseReferentialAction($tokens);
            } elseif ($tokens->accept('ON', 'DELETE')) {
                $onDelete = $this->parseReferentialAction($tokens);
            } elseif ($tokens->accept('ON', 'INSERT')) {
                $this->parseReferentialAction($tokens);
            } else {
                return [$match, $onUpdate, $onDelete];
            }
        }
    }

    /**
     * Apply a deferral clause to the table's most recently declared foreign key, as SQLite does.
     */
    private function parseDeferral(TokenStream $tokens, TableDefinition $table): void
    {
        $deferrable = ! $tokens->accept('NOT');
        $tokens->accept('DEFERRABLE');
        $deferral = $deferrable ? Deferral::Immediate : null;

        if ($tokens->accept('INITIALLY', 'DEFERRED')) {
            $deferral = $deferrable ? Deferral::Deferred : null;
        } elseif ($tokens->is('INITIALLY') && ! $tokens->accept('INITIALLY', 'IMMEDIATE')) {
            throw $tokens->error('Expected DEFERRED or IMMEDIATE', 1);
        }

        $position = array_key_last($table->foreignKeys);

        // A clause before any foreign key has nothing to apply to.
        if ($position === null) {
            return;
        }

        $foreignKey = $table->foreignKeys[$position];
        $table->foreignKeys[$position] = new ForeignKey(
            $foreignKey->name,
            $foreignKey->columns,
            $foreignKey->foreignTable,
            $foreignKey->foreignColumns,
            $foreignKey->onUpdate,
            $foreignKey->onDelete,
            $foreignKey->match,
            $deferral,
        );
    }

    /**
     * Skip a constraint's ON CONFLICT clause, which only chooses how a violation is resolved.
     */
    private function skipConflictClause(TokenStream $tokens): void
    {
        if (! $tokens->accept('ON', 'CONFLICT')) {
            return;
        }

        if (! isset(self::CONFLICT_RESOLUTIONS[$tokens->text()])) {
            throw $tokens->error('Expected a conflict resolution');
        }

        $tokens->next();
    }

    /**
     * Read a standalone index on a table of the index's database.
     */
    private function parseCreateIndex(TokenStream $tokens, ParseState $state, bool $unique): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $index = $this->parseQualifiedName($tokens, 'an index name');

        if (! $tokens->accept('ON')) {
            throw $tokens->error('Expected an index table');
        }

        $tableName = $this->parseName($tokens, 'an index table');

        if ($this->isTemporary($index)) {
            return;
        }

        $key = $this->relationKey($state, new TableReference($tableName, $index->schema));

        if ($key === null) {
            $state->issues[] = $state->issue("Indexed table [{$tableName}] could not be resolved.");

            return;
        }

        $table = $state->tables[$key];

        if ($conditional && $state->hasIndex($table->schema, $index->name)) {
            return;
        }

        $columns = $this->parseIndexColumns($tokens, $state, $table);
        $where = $tokens->accept('WHERE') ? $this->parseExpression($tokens, [], 'an index predicate') : null;

        if (! $tokens->atEnd()) {
            $table->issues[] = $state->issue("Index [{$index->name}] has unsupported trailing syntax.");
        }

        $state->addIndex($key, new Index($index->name, $columns, unique: $unique, where: $where));
    }

    /**
     * Record what an ALTER TABLE statement leaves unknown, as its actions are not applied.
     */
    private function parseAlterTable(TokenStream $tokens, ParseState $state): void
    {
        $reference = $this->parseQualifiedName($tokens, 'a table name');

        if ($this->isTemporary($reference)) {
            return;
        }

        // A renamed table changes the relation names that other definitions and lookups rely on.
        if ($tokens->is('RENAME', 'TO')) {
            if ($state->hasDefinitions()) {
                $state->issues[] = $state->issue('A table rename changes table identities that are not replayed.');
            }

            return;
        }

        $key = $this->relationKey($state, $reference);

        if ($key === null) {
            $state->issues[] = $state->issue("The altered table [{$reference->name}] could not be resolved.");
        } else {
            $state->tables[$key]->issues[] = $state->issue('The ALTER TABLE statement could not be applied to the schema definition.');
        }
    }

    /**
     * Remove a dropped table, view or index.
     */
    private function parseDrop(TokenStream $tokens, ParseState $state): void
    {
        $kind = match (true) {
            $tokens->accept('TABLE') => 'table',
            $tokens->accept('VIEW') => 'view',
            $tokens->accept('INDEX') => 'index',
            default => null,
        };

        if ($kind === null) {
            return;
        }

        $tokens->accept('IF', 'EXISTS');
        $reference = $this->parseQualifiedName($tokens, 'a dropped object name');

        if ($this->isTemporary($reference)) {
            return;
        }

        if ($kind === 'index') {
            $this->dropIndex($reference, $state);

            return;
        }

        $view = $kind === 'view';
        $key = $this->relationKey($state, $reference, $view);

        if ($key !== null) {
            $view ? $state->removeView($key) : $state->removeTable($key);
        } elseif ($reference->schema === null && count(($view ? $state->viewNames : $state->tableNames)[$state->normalize($reference->name)] ?? []) > 1) {
            $state->issues[] = $state->issue("Dropped object [{$reference->name}] is ambiguous without a schema.");
        }
    }

    /**
     * Remove a dropped index, which an unqualified name finds in main or else in a single attached database.
     */
    private function dropIndex(TableReference $reference, ParseState $state): void
    {
        $key = $state->indexTableKey($reference->schema ?? self::MAIN, $reference->name);

        if ($key === null && $reference->schema === null) {
            $keys = $state->indexTableKeys($reference->name);

            if (count($keys) > 1) {
                $state->issues[] = $state->issue("Dropped index [{$reference->name}] is ambiguous without a schema.");

                return;
            }

            $key = $keys[0] ?? null;
        }

        if ($key !== null) {
            $state->removeIndex($key, $reference->name);
        }
    }

    /**
     * Find the table or view a statement names: exactly when qualified, otherwise in main or else in a single attached database.
     */
    private function relationKey(ParseState $state, TableReference $reference, bool $view = false): ?string
    {
        if ($reference->schema !== null) {
            return $view ? $state->viewKey($reference) : $state->tableKey($reference);
        }

        $main = new TableReference($reference->name, self::MAIN);

        return ($view ? $state->viewKey($main) : $state->tableKey($main))
            ?? ($view ? $state->viewKey($reference) : $state->tableKey($reference));
    }
}
