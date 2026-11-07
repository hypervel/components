<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser;

use Hypervel\SchemaParser\Parsing\ColumnDefinition;
use Hypervel\SchemaParser\Parsing\MySqlLexer;
use Hypervel\SchemaParser\Parsing\ParseState;
use Hypervel\SchemaParser\Parsing\TableDefinition;
use Hypervel\SchemaParser\Parsing\TokenStream;
use Hypervel\SchemaParser\Parsing\TokenType;

/**
 * @extends Parser<MySqlLexer>
 */
class MySqlParser extends Parser
{
    /** Keywords that begin a column clause, so none of them can name a column type. */
    protected const array COLUMN_CLAUSES = [
        'AS' => true,
        'AUTO_INCREMENT' => true,
        'CHECK' => true,
        'COLLATE' => true,
        'COMMENT' => true,
        'CONSTRAINT' => true,
        'DEFAULT' => true,
        'GENERATED' => true,
        'KEY' => true,
        'NOT' => true,
        'NULL' => true,
        'PRIMARY' => true,
        'REFERENCES' => true,
        'UNIQUE' => true,
    ];

    /** The kinds of index that CREATE INDEX can name before its INDEX keyword. */
    protected const array INDEX_KINDS = [
        'UNIQUE' => true,
        'FULLTEXT' => true,
        'SPATIAL' => true,
    ];

    /** The first keywords of the constraints and indexes in a table definition. */
    private const array TABLE_ENTRY_STARTS = [
        'CONSTRAINT' => true,
        'PRIMARY' => true,
        'UNIQUE' => true,
        'FOREIGN' => true,
        'CHECK' => true,
        'INDEX' => true,
        'KEY' => true,
        'FULLTEXT' => true,
        'SPATIAL' => true,
    ];

    /** The constraints that can directly follow CONSTRAINT, which then has no name. */
    private const array UNNAMED_CONSTRAINTS = [
        'PRIMARY' => true,
        'UNIQUE' => true,
        'FOREIGN' => true,
        'CHECK' => true,
    ];

    /** Physical table options with a scalar value. */
    private const array TABLE_OPTIONS = [
        'AUTO_INCREMENT' => true,
        'AVG_ROW_LENGTH' => true,
        'CHARSET' => true,
        'CHECKSUM' => true,
        'CONNECTION' => true,
        'DELAY_KEY_WRITE' => true,
        'INSERT_METHOD' => true,
        'KEY_BLOCK_SIZE' => true,
        'MAX_ROWS' => true,
        'MIN_ROWS' => true,
        'PACK_KEYS' => true,
        'PASSWORD' => true,
        'ROW_FORMAT' => true,
        'STORAGE' => true,
        'TABLESPACE' => true,
    ];

    /** Physical column options with a scalar value. */
    private const array COLUMN_OPTIONS = [
        'COLUMN_FORMAT' => true,
        'ENGINE_ATTRIBUTE' => true,
        'SECONDARY_ENGINE_ATTRIBUTE' => true,
        'SRID' => true,
        'STORAGE' => true,
    ];

    /** Physical index options with a scalar value. */
    private const array INDEX_OPTIONS = [
        'ENGINE_ATTRIBUTE' => true,
        'KEY_BLOCK_SIZE' => true,
        'SECONDARY_ENGINE_ATTRIBUTE' => true,
    ];

    /**
     * Create the lexer for one input.
     */
    protected function createLexer(string $sql): MySqlLexer
    {
        return new MySqlLexer($sql);
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
        } elseif ($tokens->accept('DROP')) {
            $this->parseDrop($tokens, $state);
        } elseif ($tokens->accept('SET')) {
            $this->parseSet($tokens, $state);
        } elseif ($tokens->accept('USE')) {
            $state->defaultSchema = $this->parseName($tokens, 'a database name');
        } elseif ($tokens->is('RENAME', 'TABLE')) {
            $this->reportRename($state);
        }
    }

    /**
     * Apply the SQL modes that the following statements are lexed with.
     *
     * Modes are set from literal lists, saved in user variables from @@sql_mode,
     * and restored from those variables. Other values leave the modes unchanged.
     *
     * @param TokenStream<MySqlLexer> $tokens
     */
    private function parseSet(TokenStream $tokens, ParseState $state): void
    {
        $lexer = $tokens->lexer;
        $modes = [$lexer->noBackslashEscapes, $lexer->ansiQuotes];

        do {
            if (! $tokens->accept('SESSION')) {
                $tokens->accept('LOCAL');
            }

            $target = $this->parseVariable($tokens);

            if ($target !== null && $tokens->acceptSymbol('=')) {
                $type = $tokens->type();
                $literal = $type === TokenType::String || $type === TokenType::EscapedString ? $tokens->value() : null;
                $variable = $literal === null ? $this->parseVariable($tokens) : null;

                if ($literal !== null) {
                    $tokens->next();
                }

                $complete = $tokens->isSymbol(',') || $tokens->atEnd();

                if ($target[0] === '@' && $target[1] !== '@') {
                    if ($complete && $variable === '@@sql_mode') {
                        $state->savedSqlModes[$target] = $modes;
                    } else {
                        unset($state->savedSqlModes[$target]);
                    }
                } elseif ($complete && ($target === 'sql_mode' || $target === '@@sql_mode')) {
                    if ($variable !== null && isset($state->savedSqlModes[$variable])) {
                        $modes = $state->savedSqlModes[$variable];
                    } elseif ($literal !== null && preg_match('/^[A-Z0-9_,]*$/i', $literal) === 1) {
                        $list = explode(',', strtoupper($literal));
                        $modes = [in_array('NO_BACKSLASH_ESCAPES', $list, true), in_array('ANSI_QUOTES', $list, true) || in_array('ANSI', $list, true)];
                    }
                }
            }

            $tokens->skipExpression([]);
        } while ($tokens->acceptSymbol(','));

        // The modes change at the statement boundary, after its own tokens are lexed.
        $tokens->skipRest();
        [$lexer->noBackslashEscapes, $lexer->ansiQuotes] = $modes;
    }

    /**
     * Read a variable name with its @ or @@ prefix, in lower case.
     */
    private function parseVariable(TokenStream $tokens): ?string
    {
        $prefix = '';

        while ($prefix !== '@@' && $tokens->acceptSymbol('@')) {
            $prefix .= '@';
        }

        // A system variable can name the session scope, as in @@session.sql_mode.
        if ($prefix === '@@' && ($tokens->is('SESSION') || $tokens->is('LOCAL')) && $tokens->isSymbol('.', 1)) {
            $tokens->next();
            $tokens->next();
        }

        if ($tokens->type() !== TokenType::Word) {
            return null;
        }

        $name = $prefix . strtolower($tokens->text());
        $tokens->next();

        return $name;
    }

    /**
     * Read a CREATE statement for a represented object.
     */
    private function parseCreate(TokenStream $tokens, ParseState $state): void
    {
        $replace = $tokens->accept('OR', 'REPLACE');

        // Temporary tables are session objects, not part of the schema.
        if ($tokens->is('TEMPORARY')) {
            return;
        }

        $this->skipViewCharacteristics($tokens);
        $kind = $tokens->text();

        if ($tokens->accept('TABLE')) {
            $this->parseCreateTable($tokens, $state);
        } elseif ($tokens->accept('VIEW')) {
            $this->parseCreateView($tokens, $state);
        } elseif ($tokens->accept('INDEX')) {
            $this->parseCreateIndex($tokens, $state, $replace, null);
        } elseif (isset(static::INDEX_KINDS[$kind]) && $tokens->keyword(1) === 'INDEX') {
            $tokens->next();
            $tokens->next();
            $this->parseCreateIndex($tokens, $state, $replace, $kind);
        }
    }

    /**
     * Skip the algorithm, definer and security characteristics that can precede a view or routine.
     */
    private function skipViewCharacteristics(TokenStream $tokens): void
    {
        while (true) {
            if ($tokens->accept('ALGORITHM')) {
                $tokens->acceptSymbol('=');
                $tokens->next();
            } elseif ($tokens->accept('SQL', 'SECURITY')) {
                $tokens->next();
            } elseif ($tokens->accept('DEFINER')) {
                // A user with an optional host, or CURRENT_USER with optional parentheses.
                $tokens->acceptSymbol('=');
                $tokens->next();

                if ($tokens->acceptSymbol('(')) {
                    $tokens->acceptSymbol(')');
                }

                if ($tokens->acceptSymbol('@')) {
                    $tokens->next();
                }
            } else {
                return;
            }
        }
    }

    /**
     * Read a table, keeping LIKE and SELECT forms that do not declare all their columns as incomplete.
     */
    private function parseCreateTable(TokenStream $tokens, ParseState $state): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $reference = $this->parseRelationName($tokens, $state, 'a table name');

        if ($conditional && $this->relationExists($state, $reference->schema, $reference->name)) {
            return;
        }

        $table = new TableDefinition($reference->name, $reference->schema);

        if ($tokens->isSymbol('(') && $tokens->keyword(1) === 'LIKE') {
            $tokens->next();
        }

        if ($tokens->accept('LIKE')) {
            $this->parseRelationName($tokens, $state, 'a copied table name');
            $table->issues[] = $state->issue('CREATE TABLE LIKE does not contain a complete column definition.');
        } else {
            $columns = $tokens->isSymbol('(') && $tokens->keyword(1) !== 'SELECT';

            if ($columns) {
                $this->parseTableBody($tokens, $state, $table);

                // A primary key can be declared before its columns.
                $primary = array_find($table->indexes, static fn (Index $index): bool => $index->primary);

                if ($primary !== null) {
                    $this->markPrimaryKeyColumnsNotNull($primary, $table);
                }
            }

            $this->parseTableOptions($tokens, $table);
            $this->parseTableTail($tokens, $state, $table, $columns);
        }

        $state->addTable($table);
    }

    /**
     * Read table options, keeping the engine, comment and collation, until one is not recognized.
     */
    private function parseTableOptions(TokenStream $tokens, TableDefinition $table): void
    {
        while (! $tokens->atEnd()) {
            $option = $tokens->text();

            if ($tokens->accept('ENGINE')) {
                $table->engine = $this->parseOptionValue($tokens, 'ENGINE');
            } elseif ($tokens->accept('COMMENT')) {
                $tokens->acceptSymbol('=');
                $table->comment = $this->parseString($tokens, 'a table comment');
            } elseif ($tokens->accept('COLLATE') || $tokens->accept('DEFAULT', 'COLLATE')) {
                $tokens->acceptSymbol('=');
                $table->collation = $this->parseCollation($tokens);
            } elseif ($tokens->accept('CHARACTER', 'SET') || $tokens->accept('DEFAULT', 'CHARACTER', 'SET') || $tokens->accept('DEFAULT', 'CHARSET')) {
                $this->parseOptionValue($tokens, 'CHARACTER SET');
            } elseif ($tokens->accept('DATA', 'DIRECTORY') || $tokens->accept('INDEX', 'DIRECTORY')) {
                $this->parseOptionValue($tokens, "{$option} DIRECTORY");
            } elseif (isset(self::TABLE_OPTIONS[$option])) {
                $tokens->next();
                $this->parseOptionValue($tokens, $option);
            } elseif (! $this->acceptAssignedOption($tokens)) {
                return;
            }

            $tokens->acceptSymbol(',');
        }
    }

    /**
     * Read what follows a table's options, reporting clauses that can hide its columns or other facts.
     */
    protected function parseTableTail(TokenStream $tokens, ParseState $state, TableDefinition $table, bool $columns): void
    {
        // A partition clause only describes the physical layout.
        $unsupported = ! $tokens->atEnd() && ! $tokens->is('PARTITION', 'BY');

        while (! $tokens->atEnd() && ! $tokens->is('SELECT')) {
            $tokens->next();
        }

        if (! $tokens->atEnd()) {
            $table->issues[] = $state->issue('CREATE TABLE SELECT can add columns that are not declared in the schema.');

            return;
        }

        if ($unsupported) {
            $table->issues[] = $state->issue('The table contains unsupported options that may hide schema facts.');
        }

        if (! $columns) {
            $table->issues[] = $state->issue('The table declaration does not contain a complete column definition.');
        }
    }

    /**
     * Determine whether a table body entry is a constraint or index rather than a column.
     */
    protected function isTableConstraint(TokenStream $tokens): bool
    {
        return isset(self::TABLE_ENTRY_STARTS[$tokens->text()]);
    }

    /**
     * Read the name given by a CONSTRAINT keyword, which MySQL lets a constraint omit.
     */
    protected function parseConstraintName(TokenStream $tokens): ?string
    {
        return $tokens->accept('CONSTRAINT') && ! isset(self::UNNAMED_CONSTRAINTS[$tokens->text()])
            ? $this->parseName($tokens, 'a constraint name')
            : null;
    }

    /**
     * Read a column definition into the table, expanding SERIAL into the facts it stands for.
     */
    protected function parseColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): ColumnDefinition
    {
        $column = parent::parseColumn($tokens, $state, $table);

        if ($column->type->name === 'serial') {
            $column->type = new ColumnType('bigint', unsigned: true);
            $column->nullable = false;
            $column->autoIncrement = true;
            $table->indexes[] = new Index(null, [new IndexColumn($column->name)], unique: true, constraint: true);
        }

        return $column;
    }

    /**
     * Find a table's column by name, which MySQL compares case-insensitively.
     */
    protected function findColumn(TableDefinition $table, string $name): ?ColumnDefinition
    {
        if (isset($table->columns[$name])) {
            return $table->columns[$name];
        }

        $folded = $this->foldName($name);

        return array_find($table->columns, fn (ColumnDefinition $column): bool => $this->foldName($column->name) === $folded);
    }

    /**
     * Read a column type with its modifiers, enum or set labels and attributes.
     */
    protected function parseType(TokenStream $tokens, ParseState $state): ColumnType
    {
        $keyword = $tokens->keyword();

        if ($keyword === null || isset(static::COLUMN_CLAUSES[$keyword])) {
            throw $tokens->error('Expected a column type');
        }

        $tokens->next();
        $name = strtolower($keyword);

        if ($keyword === 'DOUBLE' && $tokens->accept('PRECISION')) {
            $name = 'double precision';
        } elseif ($keyword === 'CHARACTER' && $tokens->accept('VARYING')) {
            $name = 'character varying';
        }

        $values = $modifiers = [];

        if ($tokens->isSymbol('(')) {
            if ($keyword === 'ENUM' || $keyword === 'SET') {
                $values = $this->parseLabels($tokens);
            } else {
                $modifiers = $this->parseTypeModifiers($tokens);
            }
        }

        $unsigned = false;

        while (true) {
            if ($tokens->accept('UNSIGNED') || $tokens->accept('ZEROFILL')) {
                $unsigned = true;
            } elseif ($tokens->accept('CHARACTER', 'SET') || $tokens->accept('CHARSET')) {
                $this->parseOptionValue($tokens, 'CHARACTER SET');
            } elseif (! $tokens->accept('SIGNED') && ! $tokens->accept('BINARY') && ! $tokens->accept('ASCII')
                && ! $tokens->accept('UNICODE') && ! $tokens->accept('BYTE')) {
                break;
            }
        }

        return new ColumnType($name, modifiers: $modifiers, unsigned: $unsigned, values: $values);
    }

    /**
     * Read the decoded labels of an enum or set type.
     *
     * @return list<string>
     */
    private function parseLabels(TokenStream $tokens): array
    {
        $tokens->expectSymbol('(', 'Expected enum or set labels');
        $values = [];

        do {
            $values[] = $this->parseString($tokens, 'an enum or set label');
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Expected the end of the enum or set labels');

        return $values;
    }

    /**
     * Read the source text of each type modifier.
     *
     * @return list<string>
     */
    private function parseTypeModifiers(TokenStream $tokens): array
    {
        $tokens->expectSymbol('(', 'Expected type modifiers');
        $modifiers = [];

        do {
            $modifiers[] = $this->parseExpression($tokens, [], 'a type modifier');
        } while ($tokens->acceptSymbol(','));

        $tokens->expectSymbol(')', 'Unterminated type modifiers');

        return $modifiers;
    }

    /**
     * Read one column clause, returning false when it is not supported.
     */
    protected function parseColumnConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ColumnDefinition $column, ?string $name): bool
    {
        $clause = $tokens->text();

        switch ($clause) {
            case 'DEFAULT':
                $tokens->next();
                $column->default = $this->parseDefault($tokens);

                return true;
            case 'ON':
                if (! $tokens->accept('ON', 'UPDATE')) {
                    return false;
                }

                $column->onUpdate = $this->parseDefault($tokens);

                return true;
            case 'AUTO_INCREMENT':
                $tokens->next();
                $column->autoIncrement = true;
                $column->nullable = false;

                return true;
            case 'COMMENT':
                $tokens->next();
                $column->comment = $this->parseString($tokens, 'a column comment');

                return true;
            case 'AS':
                $tokens->next();
                $column->generation = $this->parseGeneration($tokens);

                return true;
            case 'INVISIBLE':
            case 'VISIBLE':
                $tokens->next();
                $column->invisible = $clause === 'INVISIBLE';

                return true;
            case 'COMPRESSED':
                // Bare COMPRESSED uses the default compression method; an assigned one is a scalar option.
                if (! $tokens->isSymbol('=', 1)) {
                    $tokens->next();

                    return true;
                }

                break;
        }

        if (isset(self::COLUMN_OPTIONS[$clause])) {
            $tokens->next();
            $this->parseOptionValue($tokens, $clause);

            return true;
        }

        return parent::parseColumnConstraint($tokens, $state, $table, $column, $name) || $this->acceptAssignedOption($tokens);
    }

    /**
     * Read a default or ON UPDATE expression as its source text.
     *
     * Dumps write a literal, possibly signed or introduced by a character set,
     * a name with optional arguments, or a parenthesized expression.
     */
    private function parseDefault(TokenStream $tokens): string
    {
        $start = $tokens->offset();

        if ($tokens->isSymbol('-') || $tokens->isSymbol('+')) {
            $tokens->next();
        }

        if (! $tokens->isSymbol('(')) {
            $type = $tokens->type();

            if ($type === TokenType::Symbol || $type === TokenType::End) {
                throw $tokens->error('Expected a default expression');
            }

            $text = $tokens->text();
            $tokens->next();
            $next = $tokens->type();

            if ($type === TokenType::Word && ($text === 'N' || $text[0] === '_')
                && ($next === TokenType::String || $next === TokenType::EscapedString)) {
                $tokens->next();
            }
        }

        if ($tokens->isSymbol('(')) {
            $tokens->skipGroup();
        }

        return $tokens->spanFrom($start);
    }

    /**
     * Read a table constraint or index, or a column constraint on the given column, returning false when it is not supported.
     */
    protected function parseConstraint(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $column, ?string $name): bool
    {
        $keyword = $tokens->text();

        if ($tokens->accept('CHECK')) {
            $expression = $this->parseGroup($tokens);
            $enforced = ! $tokens->accept('NOT', 'ENFORCED');

            if ($enforced) {
                $tokens->accept('ENFORCED');
            }

            $table->checks[] = new CheckConstraint($name, $expression, $enforced, $enforced);

            return true;
        }

        // A column's KEY attribute also declares its primary key.
        if ($tokens->accept('PRIMARY', 'KEY') || ($column !== null && $tokens->accept('KEY'))) {
            $table->indexes[] = $column === null
                ? $this->parseIndex($tokens, $state, $table, 'PRIMARY', null, unique: true, primary: true)
                : new Index('PRIMARY', [new IndexColumn($column)], unique: true, primary: true, constraint: true);

            return true;
        }

        if ($tokens->accept('UNIQUE')) {
            if (! $tokens->accept('INDEX')) {
                $tokens->accept('KEY');
            }

            if ($column === null) {
                $this->parseTableIndex($tokens, $state, $table, $name, unique: true);
            } else {
                $table->indexes[] = new Index($name, [new IndexColumn($column)], unique: true, constraint: true);
            }

            return true;
        }

        if ($column !== null) {
            if (! $tokens->accept('REFERENCES')) {
                return false;
            }

            $table->issues[] = $state->issue('Inline REFERENCES creates a foreign key only on MySQL 9.0 and later.');
            $this->parseReferences($tokens, $state, $table, $name, [$column]);

            return true;
        }

        if ($tokens->accept('INDEX') || $tokens->accept('KEY')) {
            $this->parseTableIndex($tokens, $state, $table, null);
        } elseif ($tokens->accept('FULLTEXT') || $tokens->accept('SPATIAL')) {
            if (! $tokens->accept('INDEX')) {
                $tokens->accept('KEY');
            }

            $this->parseTableIndex($tokens, $state, $table, null, strtolower($keyword));
        } elseif ($tokens->accept('FOREIGN', 'KEY')) {
            $this->parseForeignKey($tokens, $state, $table, $name);
        } else {
            return false;
        }

        return true;
    }

    /**
     * Read a table index after its kind keywords, starting from its optional name.
     */
    protected function parseTableIndex(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name, ?string $type = null, bool $unique = false): void
    {
        if (! $tokens->isSymbol('(') && ! $tokens->is('USING')) {
            $name = $this->parseName($tokens, 'an index name');
        }

        $table->indexes[] = $this->parseIndex($tokens, $state, $table, $name, $type, $unique);
    }

    /**
     * Read an index's method, keys and options.
     *
     * Every primary and unique key is a constraint, as MySQL exposes them alike.
     */
    private function parseIndex(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name, ?string $type, bool $unique, bool $primary = false, ?string $method = null): Index
    {
        $method ??= $this->parseIndexMethod($tokens);
        $columns = $this->parseIndexColumns($tokens, $state, $table);

        while (true) {
            $option = $tokens->text();

            if (isset(self::INDEX_OPTIONS[$option])) {
                $tokens->next();
                $this->parseOptionValue($tokens, $option);
            } elseif ($tokens->accept('COMMENT')) {
                $this->parseString($tokens, 'an index comment');
            } elseif ($tokens->accept('WITH', 'PARSER')) {
                $this->parseName($tokens, 'a parser name');
            } elseif ($method === null && $option === 'USING') {
                $method = $this->parseIndexMethod($tokens);
            } elseif (! $tokens->accept('VISIBLE') && ! $tokens->accept('INVISIBLE') && ! $tokens->accept('IGNORED')
                && ! $tokens->accept('NOT', 'IGNORED') && ! $this->acceptAssignedOption($tokens)) {
                break;
            }
        }

        return new Index($name, $columns, type: $type ?? $method, unique: $unique, primary: $primary, constraint: $unique);
    }

    /**
     * Read an index method declared with USING, such as btree or hash.
     */
    private function parseIndexMethod(TokenStream $tokens): ?string
    {
        return $tokens->accept('USING') ? strtolower($this->parseName($tokens, 'an index method')) : null;
    }

    /**
     * Read an index key: a parenthesized expression, or a column with an optional prefix length.
     */
    protected function parseIndexColumn(TokenStream $tokens, ParseState $state, TableDefinition $table): IndexColumn
    {
        $name = $expression = $length = null;

        if ($tokens->isSymbol('(')) {
            $expression = $this->parseGroup($tokens);
        } else {
            $name = $this->parseName($tokens, 'an index key');

            if ($tokens->acceptSymbol('(')) {
                if ($tokens->type() !== TokenType::Number) {
                    throw $tokens->error('Expected an index prefix length');
                }

                $length = (int) $tokens->text();
                $tokens->next();
                $tokens->expectSymbol(')', 'Expected the end of an index prefix length');
            }
        }

        $descending = $tokens->accept('DESC');

        if (! $descending) {
            $tokens->accept('ASC');
        }

        return new IndexColumn($name, $expression, $descending, length: $length);
    }

    /**
     * Read a foreign key after its FOREIGN KEY keywords.
     */
    private function parseForeignKey(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name): void
    {
        $indexName = $tokens->isSymbol('(') ? null : $this->parseName($tokens, 'a foreign key index name');
        $columns = $this->parseNameList($tokens, 'foreign key column');

        if (! $tokens->accept('REFERENCES')) {
            throw $tokens->error('Expected a referenced foreign table');
        }

        $this->parseReferences($tokens, $state, $table, $this->foreignKeyName($name, $indexName), $columns);
    }

    /**
     * Read a foreign key's referenced table, columns and rules after its REFERENCES keyword.
     *
     * @param list<string> $columns
     */
    protected function parseReferences(TokenStream $tokens, ParseState $state, TableDefinition $table, ?string $name, array $columns): void
    {
        $reference = $this->parseQualifiedName($tokens, 'a referenced table name');
        $foreignColumns = $tokens->isSymbol('(') ? $this->parseNameList($tokens, 'referenced column') : [];
        [$match, $onUpdate, $onDelete] = $this->parseForeignKeyRules($tokens, $state, $table);

        $table->foreignKeys[] = new ForeignKey(
            $name,
            $columns,
            // An unqualified referenced table is in the referencing table's database.
            $reference->schema === null ? new TableReference($reference->name, $table->schema) : $reference,
            $foreignColumns,
            $onUpdate,
            $onDelete,
            $match,
        );
    }

    /**
     * Name a foreign key by its constraint name, the only name it has on every MySQL version.
     *
     * Without one, MySQL 8.0.16 and later generate a name, while earlier versions used the index name.
     */
    protected function foreignKeyName(?string $constraintName, ?string $indexName): ?string
    {
        return $constraintName;
    }

    /**
     * Read a standalone index, which IF NOT EXISTS and OR REPLACE match by name within its table.
     */
    private function parseCreateIndex(TokenStream $tokens, ParseState $state, bool $replace, ?string $kind): void
    {
        $conditional = $tokens->accept('IF', 'NOT', 'EXISTS');
        $name = $this->parseName($tokens, 'an index name');
        $method = $this->parseIndexMethod($tokens);

        if (! $tokens->accept('ON')) {
            throw $tokens->error('Expected an index table');
        }

        $reference = $this->parseRelationName($tokens, $state, 'an index table');
        $key = $state->key($reference->schema, $reference->name);
        $table = $state->tables[$key] ?? null;

        if ($table === null) {
            $state->issues[] = $state->issue("Indexed table [{$reference->name}] could not be resolved.");

            return;
        }

        $unique = $kind === 'UNIQUE';
        $index = $this->parseIndex($tokens, $state, $table, $name, $unique || $kind === null ? null : strtolower($kind), $unique, method: $method);

        if ($conditional || $replace) {
            $position = $this->indexPosition($table, $name);

            if ($position !== null) {
                if ($conditional) {
                    return;
                }

                array_splice($table->indexes, $position, 1);
                $state->refreshIndexNames($key);
            } elseif (array_any($table->indexes, static fn (Index $existing): bool => $existing->name === null)) {
                // A server-generated name cannot be matched without guessing it.
                $table->issues[] = $state->issue("Index [{$name}] cannot be resolved against server-generated index names.");

                return;
            }
        }

        if (! $tokens->atEnd()) {
            $table->issues[] = $state->issue("Index [{$name}] has unsupported trailing syntax.");
        }

        $state->addIndex($key, $index);
    }

    /**
     * Find the position of a table's index by name, which MySQL compares case-insensitively.
     */
    private function indexPosition(TableDefinition $table, string $name): ?int
    {
        $position = array_find_key($table->indexes, static fn (Index $index): bool => $index->name === $name);

        if ($position !== null) {
            return $position;
        }

        $folded = $this->foldName($name);

        return array_find_key($table->indexes, fn (Index $index): bool => $index->name !== null && $this->foldName($index->name) === $folded);
    }

    /**
     * Fold the case of a column or index name as MySQL compares it, with simple Unicode lowercase.
     */
    private function foldName(string $name): string
    {
        return mb_convert_case($name, MB_CASE_LOWER_SIMPLE, 'UTF-8');
    }

    /**
     * Read an ALTER statement that can change represented facts.
     */
    private function parseAlter(TokenStream $tokens, ParseState $state): void
    {
        $tokens->accept('ONLINE');
        $tokens->accept('IGNORE');

        if ($tokens->accept('TABLE')) {
            $this->parseAlterTable($tokens, $state);

            return;
        }

        $this->skipViewCharacteristics($tokens);

        // ALTER VIEW replaces the view's query.
        if ($tokens->accept('VIEW')) {
            $this->parseCreateView($tokens, $state);
        }
    }

    /**
     * Record what an ALTER TABLE statement leaves unknown, as its actions are not applied.
     */
    private function parseAlterTable(TokenStream $tokens, ParseState $state): void
    {
        $tokens->accept('IF', 'EXISTS');
        $reference = $this->parseRelationName($tokens, $state, 'a table name');

        // Dumps disable and enable keys around their data, which changes no represented facts.
        if (($tokens->is('DISABLE', 'KEYS') || $tokens->is('ENABLE', 'KEYS')) && $tokens->type(2) === TokenType::End) {
            return;
        }

        while (! $tokens->atEnd()) {
            if ($tokens->is('RENAME') && ! in_array($tokens->keyword(1), ['COLUMN', 'INDEX', 'KEY'], true)) {
                $this->reportRename($state);

                return;
            }

            if ($tokens->isSymbol('(')) {
                $this->parseGroup($tokens);
            } else {
                $tokens->next();
            }
        }

        $table = $state->tables[$state->key($reference->schema, $reference->name)] ?? null;

        if ($table === null) {
            $state->issues[] = $state->issue("The altered table [{$reference->name}] could not be resolved.");
        } else {
            $table->issues[] = $state->issue('The ALTER TABLE statement could not be applied to the schema definition.');
        }
    }

    /**
     * Record that a renamed table is not replayed across the definitions that refer to it.
     */
    private function reportRename(ParseState $state): void
    {
        if ($state->hasDefinitions()) {
            $state->issues[] = $state->issue('A table rename changes table identities that are not replayed.');
        }
    }

    /**
     * Remove dropped tables, views and indexes, such as the drops a dump emits before its declarations.
     */
    private function parseDrop(TokenStream $tokens, ParseState $state): void
    {
        if ($tokens->accept('INDEX')) {
            $this->parseDropIndex($tokens, $state);

            return;
        }

        $view = $tokens->accept('VIEW');

        // Dropping a temporary table leaves the schema unchanged.
        if (! $view && ! $tokens->accept('TABLE')) {
            return;
        }

        $tokens->accept('IF', 'EXISTS');

        // MySQL accepts but ignores a trailing RESTRICT or CASCADE.
        do {
            $reference = $this->parseRelationName($tokens, $state, 'a dropped object name');
            $key = $state->key($reference->schema, $reference->name);

            if ($view && isset($state->views[$key])) {
                $state->removeView($key);
            } elseif (! $view && isset($state->tables[$key])) {
                $state->removeTable($key);
            }
        } while ($tokens->acceptSymbol(','));
    }

    /**
     * Remove a dropped index from its table.
     */
    private function parseDropIndex(TokenStream $tokens, ParseState $state): void
    {
        $tokens->accept('IF', 'EXISTS');
        $name = $this->parseName($tokens, 'an index name');

        if (! $tokens->accept('ON')) {
            throw $tokens->error('Expected an index table');
        }

        $reference = $this->parseRelationName($tokens, $state, 'an index table');
        $key = $state->key($reference->schema, $reference->name);
        $table = $state->tables[$key] ?? null;

        if ($table === null) {
            return;
        }

        $position = $this->indexPosition($table, $name);

        if ($position !== null) {
            array_splice($table->indexes, $position, 1);
            $state->refreshIndexNames($key);
        } elseif (array_any($table->indexes, static fn (Index $index): bool => $index->name === null)) {
            $table->issues[] = $state->issue("Index [{$name}] cannot be resolved against server-generated index names.");
        }
    }

    /**
     * Read a string literal's decoded value.
     */
    private function parseString(TokenStream $tokens, string $description): string
    {
        $type = $tokens->type();

        if ($type !== TokenType::String && $type !== TokenType::EscapedString) {
            throw $tokens->error("Expected {$description}");
        }

        $value = $tokens->value();
        $tokens->next();

        return $value;
    }

    /**
     * Read an option's scalar value after an optional equals sign, returning its decoded value.
     */
    private function parseOptionValue(TokenStream $tokens, string $option): string
    {
        $tokens->acceptSymbol('=');
        $type = $tokens->type();

        if ($type === TokenType::Symbol || $type === TokenType::End) {
            throw $tokens->error("Expected a value for {$option}");
        }

        $value = $tokens->value();
        $tokens->next();

        return $value;
    }

    /**
     * Consume an unrecognized option assigned a scalar value, such as an engine's physical setting.
     *
     * Returns false without consuming anything when the tokens are not such an option.
     */
    private function acceptAssignedOption(TokenStream $tokens): bool
    {
        $type = $tokens->type();

        if (! $tokens->isSymbol('=', 1)
            || ($type !== TokenType::QuotedIdentifier && ($type !== TokenType::Word || preg_match('/^[A-Z_][A-Z0-9_]*$/', $tokens->text()) !== 1))) {
            return false;
        }

        $value = $tokens->type(2);

        if ($value === TokenType::End || $tokens->isSymbol(',', 2) || $tokens->isSymbol(')', 2) || $tokens->isSymbol('=', 2)) {
            throw $tokens->error("Expected a value for option {$tokens->value()}");
        }

        if ($value === TokenType::Symbol) {
            return false;
        }

        $tokens->next();
        $tokens->next();
        $tokens->next();

        return true;
    }
}
