<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Parsing\ColumnDefinition;
use Hypervel\SchemaParser\Parsing\ParseState;
use Hypervel\SchemaParser\Parsing\PostgresLexer;
use Hypervel\SchemaParser\Parsing\TableDefinition;
use Hypervel\SchemaParser\Parsing\TokenStream;
use Hypervel\SchemaParser\Parsing\TokenType;

/**
 * @extends Parser<PostgresLexer>
 */
class PostgresParser extends Parser
{
    /** Keywords that end a column type or default by beginning the next column clause. */
    protected const array COLUMN_CLAUSES = [
        'COLLATE' => true,
        'DEFAULT' => true,
        'GENERATED' => true,
        'NOT' => true,
        'NULL' => true,
        'CONSTRAINT' => true,
        'CHECK' => true,
        'PRIMARY' => true,
        'UNIQUE' => true,
        'REFERENCES' => true,
        'DEFERRABLE' => true,
        'STORAGE' => true,
        'COMPRESSION' => true,
        'OPTIONS' => true,
    ];

    /** The first keywords of the column clauses that PostgreSQL reads differently from the shared grammar. */
    private const array COLUMN_CLAUSE_STARTS = [
        'NOT' => true,
        'GENERATED' => true,
        'STORAGE' => true,
        'COMPRESSION' => true,
        'OPTIONS' => true,
    ];

    /** The keywords that can modify a CREATE statement before the kind of object it creates. */
    private const array CREATE_MODIFIERS = [
        'OR' => true,
        'GLOBAL' => true,
        'LOCAL' => true,
        'TEMPORARY' => true,
        'TEMP' => true,
        'UNLOGGED' => true,
        'RECURSIVE' => true,
    ];

    /** The first keywords of table and column constraints. */
    private const array CONSTRAINT_STARTS = [
        'CHECK' => true,
        'NOT' => true,
        'PRIMARY' => true,
        'UNIQUE' => true,
        'FOREIGN' => true,
        'REFERENCES' => true,
    ];

    /** The first keywords of index parameters. */
    private const array INDEX_PARAMETER_STARTS = [
        'INCLUDE' => true,
        'WITH' => true,
        'USING' => true,
        'TABLESPACE' => true,
    ];

    /** The first keywords of constraint deferral, validation, enforcement and inheritance flags. */
    private const array CONSTRAINT_FLAG_STARTS = [
        'NOT' => true,
        'DEFERRABLE' => true,
        'INITIALLY' => true,
        'ENFORCED' => true,
        'NO' => true,
    ];

    private const array INTERVAL_FIELDS = [
        'YEAR' => true,
        'MONTH' => true,
        'DAY' => true,
        'HOUR' => true,
        'MINUTE' => true,
        'SECOND' => true,
    ];

    /** Serial pseudo-types and the integer types their columns are created with. */
    private const array SERIAL_TYPES = [
        'smallserial' => 'int2',
        'serial2' => 'int2',
        'serial' => 'int4',
        'serial4' => 'int4',
        'bigserial' => 'int8',
        'serial8' => 'int8',
    ];

    /**
     * Parse a plain-text PostgreSQL schema dump.
     */
    public function parse(string $sql): Schema
    {
        if (str_starts_with($sql, 'PGDMP') || str_contains($sql, "\0")) {
            throw new ParseException('PostgreSQL archives must be exported as plain SQL before parsing.');
        }

        return parent::parse($sql);
    }

    /**
     * Create the lexer for one input.
     */
    protected function createLexer(string $sql): PostgresLexer
    {
        return new PostgresLexer($sql);
    }

    /**
     * Read the statement's represented facts, leaving any unread remainder to be skipped.
     */
    protected function parseStatement(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('CREATE')) {
            $this->parseCreate($tokens, $state);
        } elseif ($tokens->accept('ALTER')) {
            $this->parseAlter($tokens, $state);
        } elseif ($tokens->accept('COMMENT', 'ON')) {
            $this->parseComment($tokens, $state);
        } elseif ($tokens->accept('DROP')) {
            $this->parseDrop($tokens, $state);
        } elseif ($tokens->accept('SET', 'STANDARD_CONFORMING_STRINGS') || $tokens->accept('SET', 'SESSION', 'STANDARD_CONFORMING_STRINGS')) {
            $this->parseStandardConformingStrings($tokens);
        }
    }

    /**
     * Apply a change to whether later string literals treat backslashes literally.
     *
     * @param TokenStream<PostgresLexer> $tokens
     */
    private function parseStandardConformingStrings(TokenStream $tokens): void
    {
        if (! $tokens->acceptSymbol('=') && ! $tokens->accept('TO')) {
            return;
        }

        $value = match ($tokens->type()) {
            TokenType::Word, TokenType::Number => $tokens->text(),
            TokenType::String, TokenType::EscapedString, TokenType::QuotedIdentifier => strtoupper($tokens->value()),
            default => null,
        };

        // PostgreSQL's boolean settings accept these words, their unique prefixes, 1 and 0.
        $enabled = match ($value) {
            'ON', 'TRUE', 'TRU', 'TR', 'T', 'YES', 'YE', 'Y', '1' => true,
            'OFF', 'OF', 'FALSE', 'FALS', 'FAL', 'FA', 'F', 'NO', 'N', '0' => false,
            default => null,
        };

        if ($enabled !== null && $tokens->type(1) === TokenType::End) {
            $tokens->lexer->standardConformingStrings = $enabled;
        }
    }

    /**
     * Read a CREATE statement for a represented object.
     */
    private function parseCreate(TokenStream $tokens, ParseState $state): void
    {
        if (isset(self::CREATE_MODIFIERS[$tokens->text()])) {
            $tokens->accept('OR', 'REPLACE');
            $temporary = $tokens->accept('GLOBAL') || $tokens->accept('LOCAL');
            $temporary = $tokens->accept('TEMPORARY') || $tokens->accept('TEMP') || $temporary;

            if ($temporary) {
                return;
            }

            $tokens->accept('UNLOGGED');
            $tokens->accept('RECURSIVE');
        }

        if ($tokens->accept('TABLE')) {
            $this->parseCreateTable($tokens, $state, false);
        } elseif ($tokens->accept('INDEX')) {
            $this->parseCreateIndex($tokens, $state, false);
        } elseif ($tokens->accept('UNIQUE', 'INDEX')) {
            $this->parseCreateIndex($tokens, $state, true);
        } elseif ($tokens->accept('FOREIGN', 'TABLE')) {
            $this->parseCreateTable($tokens, $state, true);
        } elseif ($tokens->accept('VIEW')) {
            $this->parseCreateView($tokens, $state);
        } elseif ($tokens->accept('MATERIALIZED', 'VIEW')) {
            $this->parseCreateView($tokens, $state, materialized: true);
        } elseif ($tokens->accept('TYPE')) {
            $this->parseCreateEnum($tokens, $state);
        } elseif ($tokens->accept('DOMAIN')) {
            $this->parseCreateDomain($tokens, $state);
        }
    }

    /**
     * Read a table, merging the facts it inherits from parents read earlier.
     */
    private function parseCreateTable(TokenStream $tokens, ParseState $state, bool $foreign): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $reference = $this->parseQualifiedName($tokens, 'a table name');

        if ($this->isTemporary($reference) || ($conditional && $this->relationExists($state, $reference->schema, $reference->name))) {
            return;
        }

        $table = new TableDefinition($reference->name, $reference->schema);

        if ($tokens->accept('PARTITION', 'OF')) {
            $table->partitionOf = $this->parseQualifiedName($tokens, 'a partitioned table name');

            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
            }

            $table->partitionBounds = $this->parsePartitionBounds($tokens);
            $parentKey = $state->tableKey($table->partitionOf);

            foreach ($parentKey === null ? [] : $state->tables[$parentKey]->columns as $name => $column) {
                $table->columns[$name] = clone $column;
            }

            $table->issues[] = $state->issue('The partition declaration does not include its complete column and constraint definitions.');
        } elseif ($tokens->isSymbol('(')) {
            $this->parseTableBody($tokens, $state, $table);

            if ($tokens->accept('INHERITS')) {
                $table = $this->parseInheritance($tokens, $state, $table);
            }

            $this->applyNotNullConstraints($table, $state);

            // A primary key can be declared before its columns.
            $primary = array_find($table->indexes, static fn (Index $index): bool => $index->primary);

            if ($primary !== null) {
                $this->markPrimaryKeyColumnsNotNull($primary, $table);
            }

            if ($foreign && $tokens->accept('SERVER')) {
                $this->parseName($tokens, 'a foreign server name');

                if ($tokens->accept('OPTIONS')) {
                    $this->parseGroup($tokens);
                }
            }

            if (! $tokens->atEnd() && ! $this->isStorageClause($tokens)) {
                $table->issues[] = $state->issue('The table has unsupported trailing declaration syntax.');
            }
        } elseif ($tokens->atEnd()) {
            throw $tokens->error('Expected a table definition');
        } else {
            $table->issues[] = $state->issue('The table declaration does not contain a complete column definition.');
        }

        $key = $state->addTable($table);

        foreach ($table->partitionOf === null ? $table->parents : [$table->partitionOf] as $parent) {
            $parentKey = $state->tableKey($parent);

            if ($parentKey !== null) {
                $state->linkChild($parentKey, $key);
            }
        }
    }

    /**
     * Determine whether a name belongs to the session's temporary schema.
     */
    protected function isTemporary(TableReference $reference): bool
    {
        return $reference->schema === 'pg_temp';
    }

    /**
     * Determine whether a known table, view or index already has the given relation name.
     */
    protected function relationExists(ParseState $state, ?string $schema, string $name): bool
    {
        return parent::relationExists($state, $schema, $name) || $state->hasIndex($schema, $name);
    }

    /**
     * Merge the inherited facts of the listed parents with the table's own declarations.
     *
     * Parents are read as they stood when the table was created. Copied columns are
     * cloned, so later changes to the child never reach a parent or a sibling.
     */
    private function parseInheritance(TokenStream $tokens, ParseState $state, TableDefinition $local): TableDefinition
    {
        $table = new TableDefinition($local->name, $local->schema);
        $table->issues = $local->issues;
        $conflicts = [];

        $tokens->expectSymbol('(', 'Expected a list of inherited tables');

        do {
            $parent = $this->parseQualifiedName($tokens, 'an inherited table name');
            $table->parents[] = $parent;
            $parentKey = $state->tableKey($parent);

            if ($parentKey === null) {
                $table->issues[] = $state->issue("Inherited table [{$parent->name}] could not be resolved.");
                continue;
            }

            $parentTable = $state->tables[$parentKey];

            if ($parentTable->issues !== []) {
                $table->issues[] = $state->issue("Inherited table [{$parent->name}] has incomplete definitions.");
            }

            foreach ($parentTable->columns as $name => $parentColumn) {
                // A NO INHERIT guarantee stays with the parent, while creating the child validates a NOT VALID one.
                $nullable = $parentColumn->noInheritNotNull || ($parentColumn->nullable && ! $parentColumn->unvalidatedNotNull);
                $column = $table->columns[$name] ?? null;

                if ($column === null) {
                    $column = clone $parentColumn;
                    $column->nullable = $nullable;
                    $column->noInheritNotNull = false;
                    $column->unvalidatedNotNull = false;
                    // Identity and comments belong to the parent; serial defaults and generated expressions are inherited.
                    $column->identity = null;
                    $column->comment = null;
                    $table->columns[$name] = $column;

                    continue;
                }

                $column->nullable = $column->nullable && $nullable;

                if ($column->default !== null && $parentColumn->default !== null && $column->default !== $parentColumn->default) {
                    $conflicts[$name]['default'] = true;
                }

                if ($column->generation !== null && $parentColumn->generation !== null
                    && ($column->generation->type !== $parentColumn->generation->type
                        || $column->generation->expression !== $parentColumn->generation->expression)) {
                    $conflicts[$name]['generation'] = true;
                }

                if (! $this->hasDefault($column)) {
                    $column->default = $parentColumn->default;
                    $column->autoIncrement = $parentColumn->autoIncrement;
                }

                $column->generation ??= $parentColumn->generation;
            }

            foreach ($parentTable->checks as $check) {
                if (! $check->noInherit) {
                    // Creating a child validates its enforced checks; later ALTER propagation does not.
                    $this->mergeCheck(new CheckConstraint($check->name, $check->expression, $check->enforced, $check->enforced), $table, $state);
                }
            }
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Unterminated inheritance list');

        foreach ($conflicts as $name => $conflict) {
            $localColumn = $local->columns[$name] ?? null;

            if (isset($conflict['default']) && ($localColumn === null || ! $this->hasDefault($localColumn))) {
                $table->issues[] = $state->issue("Inherited defaults for [{$name}] conflict without a local default.");
            }

            if (isset($conflict['generation']) && $localColumn?->generation === null) {
                $table->issues[] = $state->issue("Inherited generated expressions for [{$name}] conflict without a local expression.");
            }
        }

        foreach ($local->columns as $name => $column) {
            $inherited = $table->columns[$name] ?? null;

            if ($inherited !== null) {
                $column->nullable = $column->nullable && $inherited->nullable;

                if (! $this->hasDefault($column)) {
                    $column->default = $inherited->default;
                    $column->autoIncrement = $inherited->autoIncrement;
                }

                $column->generation ??= $inherited->generation;
                $column->collation ??= $inherited->collation;
            }

            $table->columns[$name] = $column;
        }

        foreach ($local->checks as $check) {
            $this->mergeCheck($check, $table, $state);
        }

        $table->indexes = $local->indexes;
        $table->foreignKeys = $local->foreignKeys;
        $table->notNullConstraints = $local->notNullConstraints;

        return $table;
    }

    /**
     * Determine whether a column declares a default, counting the sequence default of a serial column, which has no default text.
     */
    private function hasDefault(ColumnDefinition $column): bool
    {
        return $column->default !== null || $column->autoIncrement;
    }

    /**
     * Determine whether a table's trailing clause only describes its physical storage.
     */
    private function isStorageClause(TokenStream $tokens): bool
    {
        return $tokens->is('PARTITION', 'BY')
            || $tokens->is('USING')
            || ($tokens->is('WITH') && $tokens->isSymbol('(', 1))
            || $tokens->is('WITHOUT', 'OIDS')
            || $tokens->is('TABLESPACE')
            || $tokens->is('ON', 'COMMIT');
    }

    /**
     * Read a partition bound, leaving later storage and server clauses outside it.
     */
    private function parsePartitionBounds(TokenStream $tokens): string
    {
        $start = $tokens->offset();

        if ($tokens->accept('DEFAULT')) {
            return $tokens->spanFrom($start);
        }

        if (! $tokens->accept('FOR', 'VALUES')) {
            throw $tokens->error('Expected a partition bound');
        }

        if ($tokens->accept('FROM')) {
            $this->parseGroup($tokens);

            if (! $tokens->accept('TO')) {
                throw $tokens->error('Expected an upper partition bound');
            }
        } elseif (! $tokens->accept('IN') && ! $tokens->accept('WITH')) {
            throw $tokens->error('Expected a partition bound');
        }

        $this->parseGroup($tokens);

        return $tokens->spanFrom($start);
    }

    /**
     * Determine whether a table body entry is a constraint rather than a column.
     */
    protected function isTableConstraint(TokenStream $tokens): bool
    {
        return match ($tokens->keyword()) {
            'CONSTRAINT', 'PRIMARY', 'UNIQUE', 'FOREIGN', 'CHECK', 'NOT', 'LIKE' => true,
            // EXCLUDE is unreserved, so it can also name a column.
            'EXCLUDE' => $tokens->keyword(1) === 'USING' || $tokens->isSymbol('(', 1),
            default => false,
        };
    }

    /**
     * Read a column definition into the table.
     */
    protected function parseColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): ColumnDefinition
    {
        $column = parent::parseColumn($tokens, $state, $table);
        $serial = $column->type->schema === null ? self::SERIAL_TYPES[$column->type->name] ?? null : null;

        if ($serial !== null) {
            $column->type = new ColumnType($serial, 'pg_catalog');
            $column->nullable = false;
            $column->autoIncrement = true;
        }

        return $column;
    }

    /**
     * Read a column type, mapping SQL-standard keyword spellings to their pg_catalog names.
     *
     * Identifiers keep a null schema, because the search path may resolve them to
     * user types. Syntax that cannot be structured stays an opaque type named by
     * its source text.
     */
    protected function parseType(TokenStream $tokens, ParseState $state): ColumnType
    {
        $start = $tokens->offset();
        $first = $tokens->type();

        if (($first !== TokenType::Word && $first !== TokenType::QuotedIdentifier)
            || $this->atClause($tokens, static::COLUMN_CLAUSES)) {
            throw $tokens->error('Expected a column type');
        }

        // A dump repeats a few type spellings, so each distinct span is interpreted once.
        $mark = $tokens->mark();
        $tokens->skipExpression(static::COLUMN_CLAUSES);
        $span = $tokens->spanFrom($start);

        // String modifiers decode according to standard_conforming_strings, so their spans are not shared.
        $cacheable = ! str_contains($span, "'");

        if ($cacheable && isset($state->types[$span])) {
            return $state->types[$span];
        }

        $tokens->rewind($mark);
        $keyword = $first === TokenType::Word && ! $tokens->isSymbol('.', 1) ? $tokens->keyword() : null;
        $tokens->next();
        $name = $keyword === null ? null : $this->parseStandardTypeName($tokens, $keyword);
        $standard = $name !== null;
        $schema = 'pg_catalog';
        $intervalFields = null;

        if ($name === null) {
            $schema = null;
            $name = $tokens->value(-1);

            if ($tokens->acceptSymbol('.')) {
                $schema = $name;
                $name = $this->parseName($tokens, 'a type name');
            }
        } elseif ($name === 'interval' && ! $tokens->isSymbol('(')) {
            $intervalFields = $this->parseIntervalFields($tokens);
        }

        $modifiers = match (true) {
            $tokens->isSymbol('(') => $this->parseTypeModifiers($tokens),
            $standard && ($name === 'bpchar' || $name === 'bit') => ['1'],
            default => [],
        };

        if ($keyword === 'FLOAT' && $modifiers !== null) {
            $name = $modifiers !== [] && (int) $modifiers[0] <= 24 ? 'float4' : 'float8';
            $modifiers = [];
        } elseif ($standard && ($name === 'time' || $name === 'timestamp')) {
            if ($tokens->accept('WITH', 'TIME', 'ZONE')) {
                $name .= 'tz';
            } else {
                $tokens->accept('WITHOUT', 'TIME', 'ZONE');
            }
        }

        $dimensions = 0;

        if ($tokens->accept('ARRAY')) {
            $dimensions = 1;

            if ($tokens->acceptSymbol('[')) {
                if ($tokens->type() === TokenType::Number) {
                    $tokens->next();
                }

                $tokens->expectSymbol(']', 'Expected the end of an array bound');
            }
        }

        while ($tokens->acceptSymbol('[')) {
            if ($tokens->type() === TokenType::Number) {
                $tokens->next();
            }

            $tokens->expectSymbol(']', 'Expected the end of an array bound');
            ++$dimensions;
        }

        $complete = $this->atEntryEnd($tokens) || $this->atClause($tokens, static::COLUMN_CLAUSES);

        if (! $complete) {
            $this->parseExpression($tokens, static::COLUMN_CLAUSES, 'a column type');
        }

        $type = $complete && $modifiers !== null
            ? new ColumnType($name, $schema, $modifiers, $dimensions, intervalFields: $intervalFields)
            : new ColumnType($span);

        if ($cacheable) {
            $state->types[$span] = $type;
        }

        return $type;
    }

    /**
     * Read the rest of an SQL-standard type spelling, returning its pg_catalog name.
     */
    private function parseStandardTypeName(TokenStream $tokens, string $keyword): ?string
    {
        return match ($keyword) {
            'INT', 'INTEGER' => 'int4',
            'SMALLINT' => 'int2',
            'BIGINT' => 'int8',
            'REAL' => 'float4',
            'FLOAT' => 'float8',
            'DOUBLE' => $tokens->accept('PRECISION') ? 'float8' : null,
            'DEC', 'DECIMAL', 'NUMERIC' => 'numeric',
            'BOOLEAN' => 'bool',
            'CHAR', 'CHARACTER', 'NCHAR' => $tokens->accept('VARYING') ? 'varchar' : 'bpchar',
            'NATIONAL' => $tokens->accept('CHARACTER') || $tokens->accept('CHAR')
                ? ($tokens->accept('VARYING') ? 'varchar' : 'bpchar')
                : null,
            'VARCHAR' => 'varchar',
            'BIT' => $tokens->accept('VARYING') ? 'varbit' : 'bit',
            'TIME', 'TIMESTAMP', 'INTERVAL', 'JSON' => strtolower($keyword),
            default => null,
        };
    }

    /**
     * Read the fields that restrict an interval, such as `day to second`.
     */
    private function parseIntervalFields(TokenStream $tokens): ?string
    {
        if (! isset(self::INTERVAL_FIELDS[$tokens->keyword() ?? ''])) {
            return null;
        }

        $fields = $tokens->value();
        $tokens->next();

        if ($tokens->is('TO') && isset(self::INTERVAL_FIELDS[$tokens->keyword(1) ?? ''])) {
            $tokens->next();
            $fields .= ' to ' . $tokens->value();
            $tokens->next();
        }

        return $fields;
    }

    /**
     * Read type modifiers, returning null when one is not a constant or a name.
     *
     * @return null|list<string>
     */
    private function parseTypeModifiers(TokenStream $tokens): ?array
    {
        $tokens->expectSymbol('(', 'Expected type modifiers');
        $modifiers = [];
        $supported = true;

        do {
            $start = $tokens->offset();
            $type = $tokens->type();

            if (($tokens->isSymbol('-') || $tokens->isSymbol('+')) && $tokens->type(1) === TokenType::Number) {
                $tokens->next();
                $tokens->next();
                $modifier = $tokens->spanFrom($start);
            } elseif ($type === TokenType::Symbol || $type === TokenType::End) {
                $modifier = null;
            } else {
                $modifier = $tokens->value();
                $tokens->next();
            }

            if ($modifier !== null && ($tokens->isSymbol(',') || $tokens->isSymbol(')'))) {
                $modifiers[] = $modifier;
            } else {
                $supported = false;
                $this->parseExpression($tokens, [], 'a type modifier');
            }
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Unterminated type modifiers');

        return $supported ? $modifiers : null;
    }

    /**
     * Read one column clause, returning false when it is not supported.
     */
    protected function parseColumnConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ColumnDefinition $column, ?string $name): bool
    {
        if (! isset(self::COLUMN_CLAUSE_STARTS[$tokens->text()])) {
            return parent::parseColumnConstraint($tokens, $state, $table, $column, $name);
        }

        if ($tokens->accept('NOT', 'NULL')) {
            $flags = $this->parseConstraintFlags($tokens);
            $this->applyNotNull($column, $flags['valid'], $flags['noInherit']);

            return true;
        }

        if ($tokens->accept('GENERATED')) {
            $identity = $this->parseIdentityKind($tokens);

            if ($identity === null || ! $tokens->accept('AS')) {
                return false;
            }

            if ($tokens->accept('IDENTITY')) {
                $column->identity = $identity;
                $column->nullable = false;

                if ($tokens->isSymbol('(')) {
                    $this->parseGroup($tokens);
                }

                return true;
            }

            $column->generation = $this->parseGeneration($tokens);

            return true;
        }

        if ($tokens->accept('STORAGE') || $tokens->accept('COMPRESSION')) {
            $this->parseName($tokens, 'a column storage setting');

            return true;
        }

        if ($tokens->accept('OPTIONS')) {
            $this->parseGroup($tokens);

            return true;
        }

        return parent::parseColumnConstraint($tokens, $state, $table, $column, $name);
    }

    /**
     * Read whether an identity is generated always or by default.
     */
    private function parseIdentityKind(TokenStream $tokens): ?Identity
    {
        return match (true) {
            $tokens->accept('ALWAYS') => Identity::Always,
            $tokens->accept('BY', 'DEFAULT') => Identity::ByDefault,
            default => null,
        };
    }

    /**
     * Read a table constraint, or a column constraint on the given column, returning false when it is not supported.
     */
    protected function parseConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name): bool
    {
        if (! isset(self::CONSTRAINT_STARTS[$tokens->text()])) {
            // An exclusion constraint is not represented, and neither is the index it creates.
            $table->unrepresentedIndexes = $table->unrepresentedIndexes || $tokens->is('EXCLUDE');

            return false;
        }

        if ($tokens->accept('CHECK')) {
            $expression = $this->parseGroup($tokens);
            $flags = $this->parseConstraintFlags($tokens);
            $this->mergeCheck(new CheckConstraint($name, $expression, $flags['valid'], $flags['enforced'], $flags['noInherit']), $table, $state);

            return true;
        }

        if ($tokens->accept('NOT', 'NULL')) {
            $notNull = $this->parseName($tokens, 'a column name');
            $flags = $this->parseConstraintFlags($tokens);
            $table->notNullConstraints[$notNull] = ['valid' => $flags['valid'], 'noInherit' => $flags['noInherit']];

            return true;
        }

        $primary = $tokens->accept('PRIMARY', 'KEY');

        if ($primary || $tokens->accept('UNIQUE')) {
            $this->parseKeyConstraint($tokens, $state, $table, $column, $name, $primary);

            return true;
        }

        $temporal = false;

        if ($tokens->accept('FOREIGN', 'KEY')) {
            [$columns, $temporal] = $this->parseForeignKeyColumns($tokens);

            if (! $tokens->accept('REFERENCES')) {
                throw $tokens->error('Expected a referenced foreign table');
            }
        } elseif ($column !== null && $tokens->accept('REFERENCES')) {
            $columns = [$column];
        } else {
            return false;
        }

        $this->parseReferences($tokens, $state, $table, $name, $columns, $temporal);

        return true;
    }

    /**
     * Read a primary key or unique constraint, merging an existing index it adopts.
     */
    private function parseKeyConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name, bool $primary): void
    {
        $nullsNotDistinct = $tokens->accept('NULLS', 'NOT', 'DISTINCT');

        if (! $nullsNotDistinct) {
            $tokens->accept('NULLS', 'DISTINCT');
        }

        $existing = null;
        $position = null;

        // USING INDEX TABLESPACE only places a new index, so it is read with the index parameters.
        if ($tokens->is('USING', 'INDEX') && $tokens->keyword(2) !== 'TABLESPACE') {
            $tokens->accept('USING', 'INDEX');
            $indexName = $this->parseName($tokens, 'an index name');
            $position = array_find_key($table->indexes, static fn (Index $index): bool => $index->name === $indexName);

            if ($position === null) {
                $table->issues[] = $state->issue("Constraint index [{$indexName}] could not be resolved.");
            } else {
                $existing = $table->indexes[$position];
            }

            $columns = $existing->columns ?? [];
            $include = $existing->include ?? [];
        } else {
            $columns = $column === null ? $this->parseIndexColumns($tokens, $state, $table) : [new IndexColumn($column)];
            $include = $this->parseIndexParameters($tokens);
        }

        $flags = $this->parseConstraintFlags($tokens);
        $index = new Index(
            $name ?? $existing?->name,
            $columns,
            $include,
            $existing->type ?? 'btree',
            unique: true,
            primary: $primary,
            constraint: true,
            nullsNotDistinct: $existing === null ? $nullsNotDistinct : $existing->nullsNotDistinct,
            deferral: $flags['deferral'],
            where: $existing?->where,
        );

        if ($position === null) {
            $table->indexes[] = $index;
        } else {
            $table->indexes[$position] = $index;
        }
    }

    /**
     * Read foreign key columns, telling a PERIOD modifier from a column named period.
     *
     * @return array{list<string>, bool}
     */
    private function parseForeignKeyColumns(TokenStream $tokens): array
    {
        $tokens->expectSymbol('(', 'Expected a foreign key column list');
        $columns = [];
        $temporal = false;

        do {
            if ($tokens->is('PERIOD') && in_array($tokens->type(1), [TokenType::Word, TokenType::QuotedIdentifier], true)) {
                $tokens->next();
                $temporal = true;
            }

            $columns[] = $this->parseName($tokens, 'a foreign key column name');
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Expected the end of the foreign key column list');

        return [$columns, $temporal];
    }

    /**
     * Read the referenced table, columns, match mode and actions of a foreign key.
     *
     * @param list<string> $columns
     */
    private function parseReferences(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name, array $columns, bool $temporal): void
    {
        $issues = count($table->issues);
        $reference = $this->parseQualifiedName($tokens, 'a referenced table name');
        $foreignColumns = [];

        if ($tokens->isSymbol('(')) {
            [$foreignColumns, $foreignTemporal] = $this->parseForeignKeyColumns($tokens);
            $temporal = $temporal || $foreignTemporal;
        }

        if ($temporal) {
            $table->issues[] = $state->issue('Temporal foreign key semantics are not represented.');
        }

        [$match, $onUpdate, $onDelete] = $this->parseForeignKeyRules($tokens, $state, $table);
        $flags = $this->parseConstraintFlags($tokens);
        $table->foreignKeys[] = new ForeignKey(
            $name,
            $columns,
            $reference,
            $foreignColumns,
            $onUpdate,
            $onDelete,
            $match,
            $flags['deferral'],
            $flags['valid'],
            $flags['enforced'],
        );

        // A partition's copy of the foreign key is only partly represented too.
        if (count($table->issues) > $issues) {
            $table->foreignKeyIssues[array_key_last($table->foreignKeys)] = array_column(array_slice($table->issues, $issues), 'message');
        }
    }

    /**
     * Read the deferral, validation, enforcement and inheritance flags that follow a constraint.
     *
     * @return array{deferral: ?Deferral, valid: bool, enforced: bool, noInherit: bool}
     */
    private function parseConstraintFlags(TokenStream $tokens): array
    {
        $deferrable = false;
        $initially = Deferral::Immediate;
        $valid = true;
        $enforced = true;
        $noInherit = false;

        while (isset(self::CONSTRAINT_FLAG_STARTS[$tokens->text()])) {
            if ($tokens->accept('NOT', 'DEFERRABLE')) {
                $deferrable = false;
            } elseif ($tokens->accept('DEFERRABLE')) {
                $deferrable = true;
            } elseif ($tokens->accept('INITIALLY', 'DEFERRED')) {
                $initially = Deferral::Deferred;
            } elseif ($tokens->accept('INITIALLY', 'IMMEDIATE')) {
                $initially = Deferral::Immediate;
            } elseif ($tokens->accept('NOT', 'VALID')) {
                $valid = false;
            } elseif ($tokens->accept('NOT', 'ENFORCED')) {
                $enforced = false;
            } elseif ($tokens->accept('ENFORCED')) {
                $enforced = true;
            } elseif ($tokens->accept('NO', 'INHERIT')) {
                $noInherit = true;
            } else {
                break;
            }
        }

        return [
            'deferral' => $deferrable || $initially === Deferral::Deferred ? $initially : null,
            'valid' => $valid && $enforced,
            'enforced' => $enforced,
            'noInherit' => $noInherit,
        ];
    }

    /**
     * Apply a NOT NULL constraint to a column.
     */
    private function applyNotNull(ColumnDefinition $column, bool $valid, bool $noInherit): void
    {
        if ($valid) {
            $column->nullable = false;
        } else {
            $column->unvalidatedNotNull = true;
        }

        $column->noInheritNotNull = $noInherit;
    }

    /**
     * Apply the table's pending NOT NULL constraints to its columns.
     */
    private function applyNotNullConstraints(TableDefinition $table, ParseState $state): void
    {
        foreach ($table->notNullConstraints as $name => $constraint) {
            $column = $table->columns[$name] ?? null;

            if ($column === null) {
                $table->issues[] = $state->issue("The NOT NULL column [{$name}] could not be resolved.");
            } else {
                $this->applyNotNull($column, $constraint['valid'], $constraint['noInherit']);
            }
        }

        $table->notNullConstraints = [];
    }

    /**
     * Merge a check with an equal named one, keeping the stronger validation and enforcement.
     */
    private function mergeCheck(CheckConstraint $check, TableDefinition $table, ParseState $state): void
    {
        if ($check->name !== null) {
            foreach ($table->checks as $position => $existing) {
                if ($existing->name !== $check->name) {
                    continue;
                }

                if ($existing->expression !== $check->expression) {
                    $table->issues[] = $state->issue("Check [{$check->name}] has conflicting expressions.");
                } else {
                    $table->checks[$position] = new CheckConstraint(
                        $check->name,
                        $check->expression,
                        $existing->valid || $check->valid,
                        $existing->enforced || $check->enforced,
                        $existing->noInherit && $check->noInherit,
                    );
                }

                return;
            }
        }

        $table->checks[] = $check;
    }

    /**
     * Read a standalone index, keeping its key and payload columns apart.
     */
    private function parseCreateIndex(TokenStream $tokens, ParseState $state, bool $unique): void
    {
        $tokens->accept('CONCURRENTLY');
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $name = $tokens->is('ON') ? null : $this->parseName($tokens, 'an index name');

        if (! $tokens->accept('ON')) {
            throw $tokens->error('Expected an index table');
        }

        $only = $tokens->accept('ONLY');
        $reference = $this->parseQualifiedName($tokens, 'an index table');
        $key = $state->tableKey($reference);

        if ($key === null) {
            if ($state->viewKey($reference) === null) {
                $state->issues[] = $state->issue("Indexed table [{$reference->name}] could not be resolved.");
            }

            return;
        }

        $table = $state->tables[$key];

        // An index is created in its table's schema.
        if ($conditional && $name !== null && $this->relationExists($state, $table->schema, $name)) {
            return;
        }

        $method = $tokens->accept('USING') ? strtolower($this->parseName($tokens, 'an index method')) : 'btree';
        $columns = $this->parseIndexColumns($tokens, $state, $table);
        $include = $this->parseIndexParameters($tokens);
        $nullsNotDistinct = $tokens->accept('NULLS', 'NOT', 'DISTINCT');

        if ($nullsNotDistinct || $tokens->accept('NULLS', 'DISTINCT')) {
            $this->parseIndexParameters($tokens);
        }

        $where = $tokens->accept('WHERE') ? $this->parseExpression($tokens, [], 'an index predicate') : null;

        if (! $tokens->atEnd()) {
            $table->issues[] = $state->issue("Index [{$name}] has unsupported trailing syntax.");
        }

        $state->addIndex($key, new Index($name, $columns, $include, $method, $unique, nullsNotDistinct: $nullsNotDistinct, where: $where));

        if (! $only) {
            foreach ($this->partitionKeys($key, $state) as $partitionKey) {
                $this->reportPartitionIndexes($partitionKey, $state);
            }
        }
    }

    /**
     * Read an index key without confusing an expression with a column name.
     */
    protected function parseIndexColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): IndexColumn
    {
        $start = $tokens->offset();
        $name = $expression = $collation = $operatorClass = null;
        $descending = false;
        $nullsFirst = null;

        if ($tokens->isSymbol('(')) {
            $expression = $this->parseGroup($tokens);
        } else {
            $reference = $this->parseQualifiedName($tokens, 'an index key');

            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
                $expression = $tokens->spanFrom($start);
            } elseif ($reference->schema !== null) {
                $expression = $tokens->spanFrom($start);
                $table->issues[] = $state->issue('An index key has an unsupported qualified column expression.');
            } else {
                $name = $reference->name;
            }
        }

        while (! $this->atEntryEnd($tokens)) {
            if ($tokens->accept('COLLATE')) {
                $collation = $this->parseCollation($tokens);
            } elseif ($tokens->accept('ASC')) {
                $descending = false;
            } elseif ($tokens->accept('DESC')) {
                $descending = true;
            } elseif ($tokens->accept('NULLS', 'FIRST')) {
                $nullsFirst = true;
            } elseif ($tokens->accept('NULLS', 'LAST')) {
                $nullsFirst = false;
            } elseif ($tokens->accept('WITHOUT', 'OVERLAPS')) {
                $table->issues[] = $state->issue('Temporal index semantics are not represented.');
                $this->skipEntry($tokens);
                $name = null;
                $expression = $tokens->spanFrom($start);
            } elseif ($operatorClass === null && in_array($tokens->type(), [TokenType::Word, TokenType::QuotedIdentifier], true)) {
                $operatorStart = $tokens->offset();
                $this->parseQualifiedName($tokens, 'an operator class');

                if ($tokens->isSymbol('(')) {
                    $this->parseGroup($tokens);
                }

                $operatorClass = $tokens->spanFrom($operatorStart);
            } else {
                $table->issues[] = $state->issue('An index key has unsupported trailing syntax.');
                $this->skipEntry($tokens);
            }
        }

        return new IndexColumn($name, $expression, $descending, $nullsFirst, collation: $collation, operatorClass: $operatorClass);
    }

    /**
     * Read included payload columns, skipping physical index settings.
     *
     * @return list<string>
     */
    private function parseIndexParameters(TokenStream $tokens): array
    {
        $include = [];

        while (isset(self::INDEX_PARAMETER_STARTS[$tokens->text()])) {
            if ($tokens->accept('INCLUDE')) {
                $include = $this->parseNameList($tokens, 'column');
            } elseif ($tokens->accept('WITH')) {
                $this->parseGroup($tokens);
            } elseif ($tokens->accept('USING', 'INDEX', 'TABLESPACE') || $tokens->accept('TABLESPACE')) {
                $this->parseName($tokens, 'a tablespace name');
            } else {
                break;
            }
        }

        return $include;
    }

    /**
     * Determine whether a WITH clause is the view's trailing check option or data option.
     */
    protected function isViewOption(TokenStream $tokens): bool
    {
        $offset = $tokens->keyword(1) === 'NO' ? 2 : 1;

        return ($tokens->keyword($offset) === 'DATA' && $tokens->type($offset + 1) === TokenType::End)
            || parent::isViewOption($tokens);
    }

    /**
     * Read an enum type's labels with the literal rules active at its declaration.
     */
    private function parseCreateEnum(TokenStream $tokens, ParseState $state): void
    {
        $reference = $this->parseQualifiedName($tokens, 'a type name');

        if (! $tokens->accept('AS', 'ENUM')) {
            return;
        }

        $tokens->expectSymbol('(', 'Expected an enum label list');
        $values = [];

        if (! $tokens->acceptSymbol(')')) {
            do {
                $value = $this->parseLiteral($tokens);

                if ($value === null) {
                    $state->issues[] = $state->issue('An enum label could not be decoded as a string literal.');
                    $this->skipEntry($tokens);
                } else {
                    $values[] = $value;
                }
            } while ($tokens->acceptSymbol(','));

            $tokens->expectSymbol(')', 'Unterminated enum label list');
        }

        $state->enums[$state->key($reference->schema, $reference->name)] = new EnumType($reference->name, $values, $reference->schema);
    }

    /**
     * Read a domain with its own facts, leaving its base type to be resolved when the schema is frozen.
     */
    private function parseCreateDomain(TokenStream $tokens, ParseState $state): void
    {
        $reference = $this->parseQualifiedName($tokens, 'a domain name');
        $tokens->accept('AS');
        $type = $this->parseType($tokens, $state);
        $nullable = true;
        $default = null;
        $checks = [];

        while (! $tokens->atEnd()) {
            $name = $this->parseConstraintName($tokens);

            if ($tokens->accept('NOT', 'NULL')) {
                $nullable = false;
            } elseif ($tokens->accept('DEFAULT')) {
                $default = $this->parseExpression($tokens, static::COLUMN_CLAUSES, 'a default expression');
            } elseif ($tokens->accept('CHECK')) {
                $expression = $this->parseGroup($tokens);
                $flags = $this->parseConstraintFlags($tokens);
                $checks[] = new CheckConstraint($name, $expression, $flags['valid'], $flags['enforced']);
            } elseif ($tokens->accept('COLLATE')) {
                $this->parseCollation($tokens);
            } elseif (! $tokens->accept('NULL')) {
                $state->issues[] = $state->issue("Domain [{$reference->name}] has unsupported declaration syntax.");
                break;
            }
        }

        $key = $state->key($reference->schema, $reference->name);
        $state->domains[$key] = new Domain($reference->name, $type, $reference->schema, $nullable, $default, $checks);
        $state->domainNames[$reference->name][$key] = true;
    }

    /**
     * Read an ALTER statement that can change represented facts.
     */
    private function parseAlter(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('TABLE') || $tokens->accept('FOREIGN', 'TABLE')) {
            $this->parseAlterTable($tokens, $state);
        } elseif ($tokens->accept('DOMAIN')) {
            $this->parseAlterDomain($tokens, $state);
        } elseif ($tokens->accept('TYPE')) {
            $this->parseQualifiedName($tokens, 'a type name');

            if ($this->isIdentityChange($tokens) || $tokens->is('ADD', 'VALUE') || $tokens->is('RENAME', 'VALUE')) {
                $this->reportIdentityChange($state);
            }
        } else {
            while (! $tokens->atEnd()) {
                if ($this->isIdentityChange($tokens)) {
                    $this->reportIdentityChange($state);

                    return;
                }

                if ($tokens->isSymbol('(')) {
                    $this->parseGroup($tokens);
                } else {
                    $tokens->next();
                }
            }
        }
    }

    /**
     * Determine whether the rest of the statement renames an object or moves it to another schema.
     */
    private function isIdentityChange(TokenStream $tokens): bool
    {
        if (! $tokens->is('RENAME', 'TO') && ! $tokens->is('SET', 'SCHEMA')) {
            return false;
        }

        $name = $tokens->type(2);

        return ($name === TokenType::Word || $name === TokenType::QuotedIdentifier)
            && $tokens->type(3) === TokenType::End;
    }

    /**
     * Record that an identity or enum label change is not replayed across dependent definitions.
     */
    private function reportIdentityChange(ParseState $state): void
    {
        if ($state->hasDefinitions()) {
            $state->issues[] = $state->issue('An ALTER statement changes identities or enum labels that cannot be resolved across dependent definitions.');
        }
    }

    /**
     * Record that a cascading drop may change definitions that are not replayed.
     */
    private function reportCascade(ParseState $state): void
    {
        if ($state->hasDefinitions()) {
            $state->issues[] = $state->issue('Cascading drops can change dependent definitions that are not replayed.');
        }
    }

    /**
     * Apply ALTER TABLE actions to a table read earlier.
     */
    private function parseAlterTable(TokenStream $tokens, ParseState $state): void
    {
        $tokens->accept('IF', 'EXISTS');
        $only = $tokens->accept('ONLY');
        $reference = $this->parseQualifiedName($tokens, 'a table name');
        $tokens->acceptSymbol('*');

        if ($this->isIdentityChange($tokens)) {
            $this->reportIdentityChange($state);

            return;
        }

        $view = $state->viewKey($reference) !== null;
        $key = $view ? null : $state->tableKey($reference);

        do {
            $this->parseAlterTableAction($tokens, $state, $reference, $key, $view, $only);
        } while ($tokens->acceptSymbol(','));
    }

    /**
     * Apply one ALTER TABLE action, or record what it leaves unknown.
     */
    private function parseAlterTableAction(TokenStream $tokens, ParseState $state, TableReference $reference, ?string $key, bool $view, bool $only): void
    {
        if ($tokens->is('DROP')) {
            // A clean dump drops constraints before it creates their tables.
            $constraint = $tokens->keyword(1) === 'CONSTRAINT';

            if ($this->endsWithCascade($tokens)) {
                $this->reportCascade($state);
            } elseif ($key !== null) {
                $state->tables[$key]->issues[] = $state->issue('An ALTER TABLE action could not be represented.');
            } elseif (! $view && ! $constraint) {
                $state->issues[] = $state->issue("The altered table [{$reference->name}] could not be resolved.");
            }

            return;
        }

        // View column defaults and options are not represented.
        if ($view || $this->isIgnoredAlterTableAction($tokens)) {
            $this->skipEntry($tokens);

            return;
        }

        if ($key === null) {
            if (! $this->isDropDefault($tokens)) {
                $state->issues[] = $state->issue("The altered table [{$reference->name}] could not be resolved.");
            }

            $this->skipEntry($tokens);

            return;
        }

        if ($tokens->accept('ALTER')) {
            $this->parseAlterColumn($tokens, $state, $key, $only);
        } elseif ($tokens->accept('ADD')) {
            $this->parseAddConstraint($tokens, $state, $key, $only);
        } elseif ($tokens->accept('ATTACH', 'PARTITION')) {
            $partition = $this->parseQualifiedName($tokens, 'a partition name');
            $partitionKey = $state->tableKey($partition);
            $table = $state->tables[$key];

            if ($partitionKey === null) {
                $table->issues[] = $state->issue("Partition [{$partition->name}] could not be resolved.");
            } else {
                $state->tables[$partitionKey]->partitionOf = new TableReference($table->name, $table->schema);
                $state->tables[$partitionKey]->partitionBounds = $this->parsePartitionBounds($tokens);
                $state->linkChild($key, $partitionKey);

                // The partition gets the parent's foreign keys and indexes. A dump attaches partitions before creating either.
                foreach ($table->foreignKeys as $position => $foreignKey) {
                    $this->addPartitionForeignKey($foreignKey, $table->foreignKeyIssues[$position] ?? [], $partitionKey, $state);
                }

                if ($table->indexes !== [] || $table->unrepresentedIndexes) {
                    $this->reportPartitionIndexes($partitionKey, $state);
                }

                if ($table->unresolvedForeignKeys) {
                    $this->reportUnresolvedForeignKeys($partitionKey, $state);
                }
            }

            $this->skipEntry($tokens);
        } else {
            $state->tables[$key]->issues[] = $state->issue('An ALTER TABLE action could not be represented.');
            $this->skipEntry($tokens);
        }
    }

    /**
     * Skip to the end of the current list entry, determining whether it ends with CASCADE.
     */
    private function endsWithCascade(TokenStream $tokens): bool
    {
        $cascade = false;
        $qualified = false;

        while (! $this->atEntryEnd($tokens)) {
            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
                $cascade = $qualified = false;

                continue;
            }

            $cascade = ! $qualified && $tokens->is('CASCADE');
            $qualified = $tokens->isSymbol('.');
            $tokens->next();
        }

        return $cascade;
    }

    /**
     * Determine whether an ALTER TABLE action changes only facts the schema does not represent.
     */
    private function isIgnoredAlterTableAction(TokenStream $tokens): bool
    {
        // Every ENABLE, DISABLE, FORCE and NO FORCE action sets a trigger, rule or row security mode.
        return match ($tokens->text()) {
            'OWNER' => $tokens->is('OWNER', 'TO'),
            'SET' => $tokens->is('SET', 'TABLESPACE') || $tokens->is('SET', 'ACCESS', 'METHOD'),
            'CLUSTER' => $tokens->is('CLUSTER', 'ON'),
            'REPLICA' => $tokens->is('REPLICA', 'IDENTITY'),
            'ENABLE', 'DISABLE', 'FORCE' => true,
            'NO' => $tokens->is('NO', 'FORCE'),
            default => false,
        };
    }

    /**
     * Determine whether an action drops a column default, as a clean dump does before creating the table.
     */
    private function isDropDefault(TokenStream $tokens): bool
    {
        $offset = $tokens->keyword(1) === 'COLUMN' ? 2 : 1;
        $column = $tokens->type($offset);

        return $tokens->is('ALTER')
            && ($column === TokenType::Word || $column === TokenType::QuotedIdentifier)
            && $tokens->keyword($offset + 1) === 'DROP'
            && $tokens->keyword($offset + 2) === 'DEFAULT';
    }

    /**
     * Apply an ALTER COLUMN action, carrying defaults and nullability to descendants unless ONLY is given.
     */
    private function parseAlterColumn(TokenStream $tokens, ParseState $state, string $key, bool $only): void
    {
        $tokens->accept('COLUMN');
        $name = $this->parseName($tokens, 'a column name');

        if ($tokens->is('SET', 'STATISTICS') || $tokens->is('SET', 'STORAGE') || $tokens->is('SET', 'COMPRESSION')
            || (($tokens->is('SET') || $tokens->is('RESET') || $tokens->is('OPTIONS')) && $tokens->isSymbol('(', 1))) {
            $this->skipEntry($tokens);

            return;
        }

        // The clause is read once and then applied to each target column.
        $change = null;
        $recursive = false;
        $dropNotNull = $tokens->is('DROP', 'NOT', 'NULL');

        if ($tokens->accept('SET', 'DEFAULT')) {
            $recursive = true;
            $default = $this->parseExpression($tokens, [], 'a default expression');
            // Replacing or dropping the default also removes a serial column's sequence default.
            $change = static function (ColumnDefinition $column) use ($default): void {
                $column->default = $default;
                $column->autoIncrement = false;
            };
        } elseif ($tokens->accept('DROP', 'DEFAULT')) {
            $recursive = true;
            $change = static function (ColumnDefinition $column): void {
                $column->default = null;
                $column->autoIncrement = false;
            };
        } elseif ($tokens->accept('SET', 'NOT', 'NULL') || $tokens->accept('DROP', 'NOT', 'NULL')) {
            $recursive = true;
            $change = static function (ColumnDefinition $column) use ($dropNotNull): void {
                $column->nullable = $dropNotNull;
                $column->unvalidatedNotNull = false;
            };
        } elseif ($tokens->accept('ADD', 'GENERATED')) {
            $identity = $this->parseIdentityKind($tokens);

            if ($identity !== null && $tokens->accept('AS', 'IDENTITY')) {
                if ($tokens->isSymbol('(')) {
                    $this->parseGroup($tokens);
                }

                $change = static function (ColumnDefinition $column) use ($identity): void {
                    $column->identity = $identity;
                    $column->nullable = false;
                };
            }
        } elseif ($tokens->accept('SET', 'GENERATED')) {
            $identity = $this->parseIdentityKind($tokens);

            if ($identity !== null) {
                $change = static function (ColumnDefinition $column) use ($identity): void {
                    $column->identity = $identity;
                };
            }
        } elseif ($tokens->accept('DROP', 'IDENTITY')) {
            $tokens->accept('IF', 'EXISTS');
            $change = static function (ColumnDefinition $column): void {
                $column->identity = null;
            };
        }

        $table = $state->tables[$key];

        if ($change === null || ! $this->atEntryEnd($tokens)) {
            $table->issues[] = $state->issue(isset($table->columns[$name])
                ? "The ALTER COLUMN action for [{$name}] could not be represented."
                : "The altered column [{$name}] could not be resolved.");
            $this->skipEntry($tokens);

            return;
        }

        foreach ($recursive && ! $only ? $this->descendants($key, $state) : [$key] as $target) {
            $targetTable = $state->tables[$target];
            $column = $targetTable->columns[$name] ?? null;

            if ($column === null) {
                $targetTable->issues[] = $state->issue("The altered column [{$name}] could not be resolved.");

                continue;
            }

            if ($dropNotNull && $target !== $key && ! $column->nullable) {
                $targetTable->issues[] = $state->issue("The remaining NOT NULL constraints on [{$name}] cannot be resolved after a recursive drop.");
            }

            $change($column);
        }
    }

    /**
     * Add a constraint from ALTER TABLE, carrying its inheritable effects to existing descendants.
     */
    private function parseAddConstraint(TokenStream $tokens, ParseState $state, string $key, bool $only): void
    {
        $table = $state->tables[$key];
        $checks = count($table->checks);
        $foreignKeys = count($table->foreignKeys);
        $indexes = $table->indexes;
        $unrepresentedIndexes = $table->unrepresentedIndexes;

        $this->parseTableConstraint($tokens, $state, $table);
        $addedIndex = $table->indexes !== $indexes;
        $addedPrimary = null;

        if ($addedIndex) {
            $state->refreshIndexNames($key);
            $primary = array_find($table->indexes, static fn (Index $index): bool => $index->primary);
            $addedPrimary = $primary === array_find($indexes, static fn (Index $index): bool => $index->primary) ? null : $primary;
        }

        if ($addedPrimary !== null) {
            $this->markPrimaryKeyColumnsNotNull($addedPrimary, $table);
        }

        $notNull = array_key_first($table->notNullConstraints);
        $this->applyNotNullConstraints($table, $state);

        if ($only) {
            return;
        }

        $check = $table->checks[$checks] ?? null;
        $column = $notNull === null ? null : ($table->columns[$notNull] ?? null);

        foreach (array_slice($this->descendants($key, $state), 1) as $childKey) {
            $child = $state->tables[$childKey];

            if ($check !== null && ! $check->noInherit) {
                $this->mergeCheck($check, $child, $state);
            }

            if ($addedPrimary !== null) {
                $this->markPrimaryKeyColumnsNotNull($addedPrimary, $child);
            }

            $childColumn = $column !== null && ! $column->noInheritNotNull ? ($child->columns[$column->name] ?? null) : null;

            if ($childColumn !== null) {
                $childColumn->nullable = $childColumn->nullable && $column->nullable;
                $childColumn->unvalidatedNotNull = $column->unvalidatedNotNull;
            }
        }

        // Inheritance children get neither foreign keys nor indexes; partitions get both.
        $partitions = $this->partitionKeys($key, $state);

        foreach (array_slice($table->foreignKeys, $foreignKeys, preserve_keys: true) as $position => $foreignKey) {
            foreach ($partitions as $partitionKey) {
                $this->addPartitionForeignKey($foreignKey, $table->foreignKeyIssues[$position] ?? [], $partitionKey, $state);
            }
        }

        // When the table already had an unrepresented index, its partitions already report it.
        if ($addedIndex || $table->unrepresentedIndexes !== $unrepresentedIndexes) {
            foreach ($partitions as $partitionKey) {
                $this->reportPartitionIndexes($partitionKey, $state);
            }
        }
    }

    /**
     * Get a table key and the keys of its existing descendants, visiting each once.
     *
     * @return list<string>
     */
    private function descendants(string $key, ParseState $state): array
    {
        $keys = [$key];
        $seen = [$key => true];

        for ($position = 0; $position < count($keys); ++$position) {
            foreach ($state->children[$keys[$position]] ?? [] as $child => $present) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $keys[] = $child;
                }
            }
        }

        return $keys;
    }

    /**
     * Get the keys of a table's direct partitions.
     *
     * A partitioned table cannot have inheritance children and other tables cannot
     * have partitions, so the first child shows what all of them are.
     *
     * @return list<string>
     */
    private function partitionKeys(string $key, ParseState $state): array
    {
        $children = $state->children[$key] ?? [];
        $first = array_key_first($children);

        return $first !== null && $state->tables[$first]->partitionOf !== null ? array_keys($children) : [];
    }

    /**
     * Give a partition, and the partitions below it, a foreign key of its parent table.
     *
     * PostgreSQL attaches an equivalent foreign key the partition declares itself, which
     * already covers the partitions below it, and otherwise clones the parent's.
     *
     * @param list<string> $issues messages that say how the foreign key is only partly represented
     */
    private function addPartitionForeignKey(ForeignKey $foreignKey, array $issues, string $key, ParseState $state): void
    {
        $partition = $state->tables[$key];
        $unknown = false;

        foreach ($partition->foreignKeys as $position => $existing) {
            if (isset($partition->attachedForeignKeys[$position])) {
                continue;
            }

            $same = $this->sameForeignKey($existing, $foreignKey, $state);

            // A partly represented foreign key cannot prove an exact match.
            if ($same === true && ($issues !== [] || isset($partition->foreignKeyIssues[$position]))) {
                $same = null;
            }

            if ($same === true) {
                $partition->attachedForeignKeys[$position] = true;

                return;
            }

            $unknown = $unknown || $same === null;
        }

        if ($unknown) {
            $this->reportUnresolvedForeignKeys($key, $state);

            return;
        }

        $position = count($partition->foreignKeys);
        $partition->attachedForeignKeys[$position] = true;
        // When the name is taken, the server generates one, differently across versions.
        $partition->foreignKeys[] = $this->constraintNameIsFree($partition, $foreignKey->name) ? $foreignKey : new ForeignKey(
            null,
            $foreignKey->columns,
            $foreignKey->foreignTable,
            $foreignKey->foreignColumns,
            $foreignKey->onUpdate,
            $foreignKey->onDelete,
            $foreignKey->match,
            $foreignKey->deferral,
            $foreignKey->valid,
            $foreignKey->enforced,
        );

        if ($issues !== []) {
            $partition->foreignKeyIssues[$position] = $issues;

            foreach ($issues as $message) {
                $partition->issues[] = $state->issue($message);
            }
        }

        foreach ($this->partitionKeys($key, $state) as $partitionKey) {
            $this->addPartitionForeignKey($foreignKey, $issues, $partitionKey, $state);
        }
    }

    /**
     * Determine whether two foreign keys are equivalent apart from their names, or null when their targets cannot be compared.
     */
    private function sameForeignKey(ForeignKey $first, ForeignKey $second, ParseState $state): ?bool
    {
        if ($first->columns !== $second->columns || $first->onUpdate !== $second->onUpdate
            || $first->onDelete !== $second->onDelete || $first->match !== $second->match
            || $first->deferral !== $second->deferral || $first->valid !== $second->valid
            || $first->enforced !== $second->enforced) {
            return false;
        }

        if ($first->foreignTable == $second->foreignTable && $first->foreignColumns === $second->foreignColumns) {
            return true;
        }

        // Different names, or different schemas, are different tables even when neither is in the dump.
        if ($first->foreignTable->name !== $second->foreignTable->name
            || ($first->foreignTable->schema !== null && $second->foreignTable->schema !== null
                && $first->foreignTable->schema !== $second->foreignTable->schema)) {
            return false;
        }

        $firstTable = $state->tableKey($first->foreignTable);
        $secondTable = $state->tableKey($second->foreignTable);
        $firstColumns = $this->referencedColumns($first, $state);
        $secondColumns = $this->referencedColumns($second, $state);

        if (($firstTable !== null && $secondTable !== null && $firstTable !== $secondTable)
            || ($firstColumns !== null && $secondColumns !== null && $firstColumns !== $secondColumns)) {
            return false;
        }

        return $firstTable === null || $secondTable === null || $firstColumns === null || $secondColumns === null ? null : true;
    }

    /**
     * Determine whether a table's constraints leave a name free, treating an unnamed one as possibly holding it.
     */
    private function constraintNameIsFree(TableDefinition $table, ?string $name): bool
    {
        $names = [
            ...array_column($table->checks, 'name'),
            ...array_column($table->foreignKeys, 'name'),
            ...array_column(array_filter($table->indexes, static fn (Index $index): bool => $index->constraint), 'name'),
        ];

        return $name !== null && ! in_array($name, $names, true) && ! in_array(null, $names, true);
    }

    /**
     * Record that a partition and the partitions below it have indexes created from their parent's, whose names the server chooses.
     */
    private function reportPartitionIndexes(string $key, ParseState $state): void
    {
        foreach ($this->descendants($key, $state) as $descendantKey) {
            $partition = $state->tables[$descendantKey];

            // Partitions attached below this one later get these indexes too.
            if (! $partition->unrepresentedIndexes) {
                $partition->unrepresentedIndexes = true;
                $partition->issues[] = $state->issue('Indexes the partition gets from its parent table are not represented.');
            }
        }
    }

    /**
     * Record that a partition and the partitions below it may be missing a parent foreign key that could not be compared with their own.
     */
    private function reportUnresolvedForeignKeys(string $key, ParseState $state): void
    {
        foreach ($this->descendants($key, $state) as $descendantKey) {
            $partition = $state->tables[$descendantKey];

            // Partitions attached below this one later may be missing it too.
            if (! $partition->unresolvedForeignKeys) {
                $partition->unresolvedForeignKeys = true;
                $partition->issues[] = $state->issue('The foreign keys the partition gets from its parent table could not be determined.');
            }
        }
    }

    /**
     * Apply an ALTER DOMAIN statement to a domain read earlier.
     */
    private function parseAlterDomain(TokenStream $tokens, ParseState $state): void
    {
        $reference = $this->parseQualifiedName($tokens, 'a domain name');

        if ($this->isIdentityChange($tokens)) {
            $this->reportIdentityChange($state);

            return;
        }

        if ($tokens->is('OWNER', 'TO')) {
            return;
        }

        $key = $state->domainKey($reference->schema, $reference->name);

        if ($key === null) {
            $state->issues[] = $state->issue("The altered domain [{$reference->name}] could not be resolved.");

            return;
        }

        $domain = $state->domains[$key];
        $nullable = $domain->nullable;
        $default = $domain->default;
        $checks = $domain->checks;
        $applied = true;

        if ($tokens->accept('ADD')) {
            $name = $this->parseConstraintName($tokens);
            $applied = $tokens->accept('CHECK');

            if ($applied) {
                $expression = $this->parseGroup($tokens);
                $flags = $this->parseConstraintFlags($tokens);
                $checks[] = new CheckConstraint($name, $expression, $flags['valid'], $flags['enforced']);

                if (! $tokens->atEnd()) {
                    $state->issues[] = $state->issue('The domain constraint has unsupported trailing syntax.');
                }
            }
        } else {
            if ($tokens->accept('SET', 'DEFAULT')) {
                $default = $this->parseExpression($tokens, [], 'a default expression');
            } elseif ($tokens->accept('DROP', 'DEFAULT')) {
                $default = null;
            } elseif ($tokens->accept('SET', 'NOT', 'NULL')) {
                $nullable = false;
            } elseif ($tokens->accept('DROP', 'NOT', 'NULL')) {
                $nullable = true;
            } else {
                $applied = false;
            }

            $applied = $applied && $tokens->atEnd();
        }

        if (! $applied) {
            $state->issues[] = $state->issue("The ALTER DOMAIN statement for [{$reference->name}] could not be applied.");

            return;
        }

        $state->domains[$key] = new Domain($domain->name, $domain->type, $domain->schema, $nullable, $default, $checks);
    }

    /**
     * Apply a decoded table or column comment.
     */
    private function parseComment(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('COLUMN')) {
            $isColumn = true;
        } elseif ($tokens->accept('TABLE') || $tokens->accept('FOREIGN', 'TABLE')) {
            $isColumn = false;
        } else {
            return;
        }

        $parts = [$this->parseName($tokens, 'a comment target')];

        while ($tokens->acceptSymbol('.')) {
            $parts[] = $this->parseName($tokens, 'a comment target');
        }

        $column = $isColumn ? array_pop($parts) : null;

        if ($parts === [] || count($parts) > 2) {
            throw $tokens->error('Expected a table or column comment target');
        }

        $reference = new TableReference($parts[count($parts) - 1], count($parts) === 2 ? $parts[0] : null);

        if (! $tokens->accept('IS')) {
            throw $tokens->error('Expected a comment value');
        }

        $removed = $tokens->is('NULL') && $tokens->type(1) === TokenType::End;
        $comment = $removed ? null : $this->parseLiteral($tokens);
        $key = $state->tableKey($reference);

        if ($key === null) {
            // View comments are not represented, but COMMENT ON COLUMN also accepts views.
            if ($state->viewKey($reference) === null) {
                $state->issues[] = $state->issue("Commented table [{$reference->name}] could not be resolved.");
            }

            return;
        }

        $table = $state->tables[$key];

        if ($comment === null && ! $removed) {
            $table->issues[] = $state->issue('A comment could not be decoded as a string literal.');
        } elseif ($column === null) {
            $table->comment = $comment;
        } elseif (isset($table->columns[$column])) {
            $table->columns[$column]->comment = $comment;
        } else {
            $table->issues[] = $state->issue("Commented column [{$column}] could not be resolved.");
        }
    }

    /**
     * Remove dropped objects, such as the drops a clean dump emits before its declarations.
     */
    private function parseDrop(TokenStream $tokens, ParseState $state): void
    {
        $kind = match (true) {
            $tokens->accept('TABLE'), $tokens->accept('FOREIGN', 'TABLE') => 'table',
            $tokens->accept('VIEW'), $tokens->accept('MATERIALIZED', 'VIEW') => 'view',
            $tokens->accept('DOMAIN') => 'domain',
            $tokens->accept('TYPE') => 'type',
            $tokens->accept('INDEX') => 'index',
            $tokens->accept('SCHEMA') => 'schema',
            default => null,
        };

        if ($kind === null) {
            do {
                $cascade = $this->endsWithCascade($tokens);
            } while ($tokens->acceptSymbol(','));

            if ($cascade) {
                $this->reportCascade($state);
            }

            return;
        }

        $tokens->accept('CONCURRENTLY');
        $tokens->accept('IF', 'EXISTS');
        $references = [];

        // Targets are read first, so objects named cascade or restrict stay targets.
        do {
            $references[] = $this->parseQualifiedName($tokens, 'an object name');
        } while ($tokens->acceptSymbol(','));

        $cascade = $tokens->accept('CASCADE');

        if (! $cascade) {
            $tokens->accept('RESTRICT');
        }

        if (! $tokens->atEnd()) {
            throw $tokens->error('Expected a DROP option or another object name');
        }

        if ($cascade) {
            $this->reportCascade($state);
        }

        foreach ($references as $reference) {
            $this->drop($kind, $reference, $cascade, $state);
        }
    }

    /**
     * Remove one exact or uniquely named dropped object.
     */
    private function drop(string $kind, TableReference $reference, bool $cascade, ParseState $state): void
    {
        if ($kind === 'index') {
            $this->dropIndex($reference, $state);

            return;
        }

        if ($kind === 'schema') {
            $name = $reference->name;

            if (! $cascade && (array_any($state->tables, static fn (TableDefinition $table): bool => $table->schema === $name)
                || array_any($state->views, static fn (View $view): bool => $view->schema === $name)
                || array_any($state->domains, static fn (Domain $domain): bool => $domain->schema === $name)
                || array_any($state->enums, static fn (EnumType $enum): bool => $enum->schema === $name))) {
                $state->issues[] = $state->issue('A dropped schema still has represented definitions.');
            }

            return;
        }

        $key = $state->key($reference->schema, $reference->name);

        if ($reference->schema === null) {
            $candidates = match ($kind) {
                'table' => $state->tableNames[$reference->name] ?? [],
                'view' => $state->viewNames[$reference->name] ?? [],
                'domain' => $state->domainNames[$reference->name] ?? [],
                default => array_filter($state->enums, static fn (EnumType $enum): bool => $enum->name === $reference->name),
            };

            if (count($candidates) > 1) {
                $state->issues[] = $state->issue("Dropped object [{$reference->name}] is ambiguous without a schema.");

                return;
            }

            $key = array_key_first($candidates) ?? $key;
        }

        if ($kind === 'table') {
            if (isset($state->tables[$key])) {
                $state->removeTable($key);
            }
        } elseif ($kind === 'view') {
            if (isset($state->views[$key])) {
                $state->removeView($key);
            }
        } elseif ($kind === 'domain') {
            unset($state->domains[$key], $state->domainNames[$reference->name][$key]);
        } else {
            unset($state->enums[$key]);
        }
    }

    /**
     * Remove a named index without choosing between ambiguous schemas.
     */
    private function dropIndex(TableReference $reference, ParseState $state): void
    {
        if ($reference->schema !== null) {
            $key = $state->indexTableKey($reference->schema, $reference->name);
        } else {
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
     * Freeze the parsed definitions after resolving domain chains.
     */
    protected function freeze(ParseState $state): Schema
    {
        $resolved = [];
        $resolving = [];

        foreach ($state->domains as $key => $domain) {
            $this->resolveDomain($key, $state, $resolved, $resolving);
        }

        $state->domains = array_replace($state->domains, $resolved);

        return parent::freeze($state);
    }

    /**
     * Resolve a domain's base chain once, detecting dependency cycles.
     *
     * @param array<string, Domain> $resolved
     * @param array<string, true> $resolving
     */
    private function resolveDomain(string $key, ParseState $state, array &$resolved, array &$resolving): Domain
    {
        if (isset($resolved[$key])) {
            return $resolved[$key];
        }

        $domain = $state->domains[$key];

        if (isset($resolving[$key])) {
            throw new ParseException("The domain [{$domain->name}] has a cyclic base type.");
        }

        $resolving[$key] = true;
        $type = $domain->type;
        $baseKey = $state->domainKey($type->schema, $type->name);
        $base = $baseKey === null ? null : $this->resolveDomain($baseKey, $state, $resolved, $resolving);

        if ($type->schema === null && count($state->domainNames[$type->name] ?? []) > 1) {
            $state->issues[] = new Issue("The base type of domain [{$domain->name}] is ambiguous without a schema.");
        }

        if ($base !== null) {
            $type = $this->withBase($type, $base->type);
        }

        // Arrays of a domain keep their element type without its default, NOT NULL or checks.
        $scalar = $type->dimensions === 0 ? $base : null;
        unset($resolving[$key]);

        return $resolved[$key] = new Domain(
            $domain->name,
            $type,
            $domain->schema,
            $domain->nullable && ($scalar->nullable ?? true),
            $domain->default ?? $scalar?->default,
            [...($scalar->checks ?? []), ...$domain->checks],
        );
    }

    /**
     * Freeze a column with its domain's resolved base type and default.
     */
    protected function freezeColumn(ColumnDefinition $column, TableDefinition $table, ParseState $state): Column
    {
        $type = $column->type;
        $domainKey = $state->domainKey($type->schema, $type->name);
        $domain = $domainKey === null ? null : $state->domains[$domainKey];

        if ($type->schema === null && count($state->domainNames[$type->name] ?? []) > 1) {
            $table->issues[] = new Issue("The domain of column [{$column->name}] is ambiguous without a schema.");
        }

        if ($domain !== null) {
            $type = $this->withBase($type, $domain->type);
        }

        $default = $column->default ?? ($type->dimensions === 0 ? $domain?->default : null);

        if ($column->nullable && $column->unvalidatedNotNull) {
            $table->issues[] = new Issue("The NOT NULL constraint on column [{$column->name}] has not been validated.");
        }

        // Domain NOT NULL checks conversion, not already-typed NULLs stored in columns.
        // https://www.postgresql.org/docs/current/sql-createdomain.html#SQL-CREATEDOMAIN-NOTES
        return new Column(
            $column->name,
            $type,
            $column->nullable,
            $default,
            $column->autoIncrement || $column->identity !== null
                || ($default !== null && preg_match('/^(?:pg_catalog\.)?nextval\s*\(/i', $default) === 1),
            $column->identity,
            $column->generation,
            collation: $column->collation,
            comment: $column->comment,
        );
    }

    /**
     * Copy a declared type with its resolved domain base.
     */
    private function withBase(ColumnType $type, ColumnType $base): ColumnType
    {
        return new ColumnType(
            $type->name,
            $type->schema,
            $type->modifiers,
            $type->dimensions,
            $type->unsigned,
            $type->values,
            $base,
            $type->intervalFields,
        );
    }
}
