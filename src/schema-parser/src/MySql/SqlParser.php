<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\MySql;

use Generator;
use Hypervel\SchemaParser\Issue;
use Hypervel\SchemaParser\ParseException;
use Hypervel\SchemaParser\ReferentialAction;

/**
 * @internal the public parser creates a reader for each input so token state is never shared
 *
 * @phpstan-type Field array{name: string, type: string, length?: string, decimals?: string, fsp?: string, unsigned?: bool, zerofill?: bool, binary?: bool, character_set?: string, collation?: string, values?: list<string>, modifiers?: list<string>, null?: bool, default?: string, on_update?: string, auto_increment?: bool, primary?: bool, unique?: bool, comment?: string, generation?: string, generation_type?: string, invisible?: bool, more?: list<string>, checks?: list<Index>}
 * @phpstan-type IndexColumn array{name?: string, expression?: string, length?: string, direction?: string}
 * @phpstan-type Index array{type: string, cols?: list<IndexColumn>, name?: string, constraint?: bool, constraint_name?: string, mode?: string, ref_table?: string, ref_database?: ?string, ref_cols?: list<IndexColumn>, ref_match?: string, ref_on_delete?: string, ref_on_update?: string, more?: list<string>, expression?: string, tokens?: list<string>, enforced?: bool, key_block_size?: string, parser?: string, comment?: string}
 * @phpstan-type Table array{name: string, database: ?string, sql?: string, fields?: list<Field>, indexes?: list<Index>, props?: array<string, string>, comment?: string, engine?: string, more?: list<string>, like?: string, like_database?: ?string, issues?: list<Issue>}
 * @phpstan-type View array{name: string, database: ?string, definition: string}
 */
class SqlParser
{
    private const int NO_BACKSLASH_ESCAPES = 1;

    private const int ANSI_QUOTES = 2;

    private int $sqlMode = 0;

    /** @var array<int, int> */
    private array $statementModes = [];

    /** @var list<string> */
    public array $tokens = [];

    /** @var array<string, Table> */
    public array $tables = [];

    /** @var array<string, View> */
    public array $views = [];

    /** @var list<Issue> */
    public array $issues = [];

    /** @var list<array{int, int}> */
    public array $source_map = [];

    public string $sql = '';

    /** @var list<array{int, int}> */
    private array $statementSourceMap = [];

    /** @var list<array{int, int}> */
    private array $definitionSourceMap = [];

    /**
     * Parse the dump into the tracked reader's intermediate representation.
     *
     * @return array<string, Table>
     */
    public function parse(string $sql): array
    {
        $this->issues = [];

        $this->lex($sql);
        $ret = $this->walk($this->tokens, $sql, $this->source_map);

        $this->tables = $ret['tables'];
        $this->views = $ret['views'];
        return $this->tables;
    }

    /**
     * Tokenize SQL and collapse compound keywords.
     *
     * @return list<string>
     */
    public function lex(string $sql): array
    {
        $this->sql = $sql;
        $this->source_map = $this->lexTokens($this->sql);
        $this->tokens = $this->_extract_tokens($this->sql, $this->source_map);
        return $this->tokens;
    }

    #
    # simple lexer based on http://www.contrib.andrew.cmu.edu/~shadow/sql/sql1992.txt
    #
    # returns an array of [position, len] tuples for each token

    /**
     * Locate tokens while retaining their positions in the original dump.
     *
     * @return list<array{int, int}>
     */
    private function lexTokens(string $sql): array
    {
        $position = 0;
        $length = strlen($sql);
        $sourceMap = [];
        $executableStart = null;
        $delimiter = ';';
        $statementStart = true;
        $identifierEnd = null;
        $qualifiedNameStart = null;
        $statementTokenStart = 0;
        $savedModes = [];
        $sqlMode = 0;
        $this->sqlMode = 0;
        $this->statementModes = [];

        while ($position < $length) {
            if (preg_match('/\s+/A', $sql, $matches, 0, $position)) {
                $position += strlen($matches[0]);
                continue;
            }

            if ($executableStart !== null && substr($sql, $position, 2) === '*/') {
                $executableStart = null;
                $position += 2;
                continue;
            }

            if ($statementStart && preg_match('/DELIMITER[\t ]+(\S+)[\t ]*(?:\r?\n|$)/Ai', $sql, $matches, 0, $position)) {
                $delimiter = $matches[1];
                $sourceMap[] = [$position, strlen(rtrim($matches[0]))];
                $position += strlen($matches[0]);
                $statementTokenStart = count($sourceMap);
                continue;
            }

            if (substr_compare($sql, $delimiter, $position, strlen($delimiter)) === 0) {
                $firstToken = $sourceMap[$statementTokenStart] ?? null;
                if ($firstToken !== null && strcasecmp(substr($sql, $firstToken[0], $firstToken[1]), 'SET') === 0) {
                    $modeTokens = [];
                    for ($cursor = $statementTokenStart, $count = count($sourceMap); $cursor < $count; ++$cursor) {
                        $modeTokens[] = substr($sql, $sourceMap[$cursor][0], $sourceMap[$cursor][1]);
                    }
                    $sqlMode = $this->applySqlMode($modeTokens, $sqlMode, $savedModes);
                }

                $sourceMap[] = [$position, strlen($delimiter)];
                $position += strlen($delimiter);
                $statementTokenStart = count($sourceMap);
                $statementStart = true;
                continue;
            }

            if ($sql[$position] === '#' || (substr($sql, $position, 2) === '--'
                && (! isset($sql[$position + 2]) || ord($sql[$position + 2]) <= 32))) {
                $end = strpos($sql, "\n", $position);
                $position = $end === false ? $length : $end + 1;
                continue;
            }

            if (substr($sql, $position, 2) === '/*') {
                if (preg_match('~/\*(?:M)?!\d*\s*~A', $sql, $matches, 0, $position)) {
                    $start = $position + strlen($matches[0]);

                    // Executable bodies are SQL: quoted terminators and inner
                    // ordinary comments must not close the outer wrapper.
                    if (substr($sql, $position, 4) !== '/*M!' || substr($sql, $start, 2) !== '\-') {
                        $executableStart ??= $position;
                        $position = $start;
                        continue;
                    }
                }

                $end = strpos($sql, '*/', $position + 2);

                if ($end === false) {
                    throw $this->syntaxError('Unterminated comment', $position);
                }

                $position = $end + 2;
                continue;
            }

            $prefixedLiteral = ($sql[$position + 1] ?? null) === "'"
                && in_array($sql[$position], ['b', 'B', 'x', 'X'], true);

            if ($statementStart && $sqlMode !== 0) {
                // Later decoding must use the mode active when this statement was tokenized.
                $this->statementModes[$position] = $sqlMode;
            }
            $statementStart = false;

            if ($sql[$position] === '.' && $identifierEnd === $position) {
                $sourceMap[] = [$position++, 1];
                $qualifiedNameStart = $position;
                continue;
            }

            if ($prefixedLiteral || in_array($sql[$position], ["'", '"', '`'], true)) {
                $quotePosition = $position + (int) $prefixedLiteral;
                $quote = $sql[$quotePosition];
                $identifierQuote = $quote === '`' || ($quote === '"' && ($sqlMode & self::ANSI_QUOTES) !== 0);
                $end = $quotePosition + 1;

                while ($end < $length) {
                    if (! $identifierQuote && ($sqlMode & self::NO_BACKSLASH_ESCAPES) === 0 && $sql[$end] === '\\') {
                        $end += 2;
                        continue;
                    }

                    if ($sql[$end] === $quote) {
                        if (($sql[$end + 1] ?? null) === $quote) {
                            $end += 2;
                            continue;
                        }

                        break;
                    }

                    ++$end;
                }

                if ($end >= $length) {
                    throw $this->syntaxError($identifierQuote ? 'Unterminated identifier' : 'Unterminated string', $position);
                }

                $sourceMap[] = [$position, $end - $position + 1];
                $position = $end + 1;
                $identifierEnd = $identifierQuote ? $position : null;
                continue;
            }

            // An adjacent qualification dot makes even 1e5foo an identifier.
            $identifierPattern = $qualifiedNameStart === $position
                ? '/[a-zA-Z0-9_$\x80-\xff]+/A'
                : '/[a-zA-Z_$\x80-\xff][a-zA-Z0-9_$\x80-\xff]*/A';
            $identifier = preg_match($identifierPattern, $sql, $matches, 0, $position) === 1;

            if ($identifier || preg_match('/(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/A', $sql, $matches, 0, $position)) {
                if (! $identifier && ctype_digit($matches[0])
                    && preg_match('/[a-zA-Z_$\x80-\xff][a-zA-Z0-9_$\x80-\xff]*/A', $sql, $suffix, 0, $position + strlen($matches[0]))) {
                    $matches[0] .= $suffix[0];
                    $identifier = true;
                }
                $tokenLength = strlen($matches[0]);
                // Custom delimiters can directly follow identifiers, such as END$$.
                $boundary = strpos($matches[0], $delimiter);
                if ($boundary !== false) {
                    $tokenLength = $boundary;
                }
                $sourceMap[] = [$position, $tokenLength];
                $position += $tokenLength;
                $identifierEnd = $identifier ? $position : null;
                continue;
            }

            $sourceMap[] = [$position, 1];
            ++$position;
        }

        if ($executableStart !== null) {
            throw $this->syntaxError('Unterminated executable comment', $executableStart);
        }

        return $sourceMap;
    }

    /**
     * Apply the literal SQL modes and saved-mode assignments emitted by dump clients.
     *
     * @param list<string> $tokens
     * @param array<string, int> $savedModes
     */
    private function applySqlMode(array $tokens, int $sqlMode, array &$savedModes): int
    {
        preg_match_all('/(?:^SET\s+|,\s*)(?:SESSION\s+)?((?:@\s*){0,2}[A-Z_]\w*)\s*=\s*((?:@\s*){1,2}[A-Z_]\w*|\x27[A-Z0-9_,]*\x27|"[A-Z0-9_,]*")(?=\s*(?:,|$))/i', implode(' ', $tokens), $assignments, PREG_SET_ORDER);

        foreach ($assignments as $assignment) {
            $target = strtolower(str_replace(' ', '', $assignment[1]));
            $value = strtolower(str_replace(' ', '', $assignment[2]));

            if (str_starts_with($target, '@') && ! str_starts_with($target, '@@')) {
                if ($value === '@@sql_mode') {
                    $savedModes[$target] = $sqlMode;
                }
                continue;
            }

            if ($target !== 'sql_mode' && $target !== '@@sql_mode') {
                continue;
            }

            if (isset($savedModes[$value])) {
                $sqlMode = $savedModes[$value];
            } elseif ($value[0] === "'" || $value[0] === '"') {
                $modes = explode(',', substr($value, 1, -1));
                $sqlMode = (in_array('no_backslash_escapes', $modes, true) ? self::NO_BACKSLASH_ESCAPES : 0)
                    | (in_array('ansi_quotes', $modes, true) || in_array('ansi', $modes, true) ? self::ANSI_QUOTES : 0);
            }
        }

        return $sqlMode;
    }

    /**
     * Locate a syntax error without counting lines on the successful path.
     */
    private function syntaxError(string $message, ?int $offset): ParseException
    {
        return new ParseException($message, $offset === null ? null : $this->line($offset));
    }

    /**
     * Locate a diagnostic in the original SQL input.
     */
    private function line(int $offset): int
    {
        return substr_count($this->sql, "\n", 0, $offset) + 1;
    }

    /**
     * Read persistent declarations in dump order.
     *
     * @param list<string> $tokens
     * @param list<array{int, int}> $source_map
     * @return array{tables: array<string, Table>, views: array<string, View>}
     */
    public function walk(array $tokens, string $sql, array $source_map): array
    {
        $tables = [];
        $views = [];
        $database = null;

        foreach ($this->statements($tokens, $sql, $source_map) as $statement) {
            $tokens = $statement['tokens'];
            $this->statementSourceMap = $statement['map'];
            $this->sqlMode = $this->statementModes[$statement['map'][0][0]] ?? 0;
            $command = strtoupper($tokens[0]);
            $replace = $command === 'CREATE' && $this->next_tokens($tokens, 1, 'OR', 'REPLACE');
            $createPosition = $replace ? 3 : 1;

            if ($command === 'USE') {
                if (! isset($tokens[1])) {
                    throw $this->syntaxError('Expected a database name after USE', $this->statementSourceMap[0][0]);
                }
                $database = $this->decode_identifier($tokens[1]);
            } elseif (in_array($command, ['CREATE TABLE', 'CREATE OR REPLACE TABLE'], true)) {
                $table = $this->parse_create_table($tokens, 1);
                $table['database'] ??= $database;
                $table['sql'] = $statement['sql'];
                $key = $this->generateTableKey($table);
                if (($tokens[1] ?? null) !== 'IF NOT EXISTS' || ! isset($tables[$key])) {
                    $tables[$key] = $table;
                }
            } elseif ($command === 'DROP' && in_array(strtoupper($tokens[1] ?? ''), ['TABLE', 'VIEW'], true)) {
                $position = 2;
                if ($this->next_tokens($tokens, $position, 'IF', 'EXISTS')) {
                    $position += 2;
                }
                do {
                    $identity = $this->readIdentity($tokens, $position);
                    $identity['database'] ??= $database;
                    $key = $this->generateTableKey($identity);
                    if (strtoupper($tokens[1]) === 'TABLE') {
                        unset($tables[$key]);
                    } else {
                        unset($views[$key]);
                    }
                } while (($tokens[$position++] ?? null) === ',');
            } elseif ($command === 'CREATE' && in_array(strtoupper($tokens[$createPosition] ?? ''), ['INDEX', 'UNIQUE INDEX', 'FULLTEXT', 'FULLTEXT INDEX', 'SPATIAL', 'SPATIAL INDEX', 'VECTOR INDEX'], true)) {
                $headerStart = $createPosition + 1;
                $conditional = ($tokens[$headerStart] ?? null) === 'IF NOT EXISTS';
                $headerStart += (int) $conditional;
                $position = $headerStart;
                while (isset($tokens[$position]) && strtoupper($tokens[$position]) !== 'ON') {
                    ++$position;
                }
                $headerEnd = $position++;
                $identity = $this->readIdentity($tokens, $position);
                $identity['database'] ??= $database;
                $key = $this->generateTableKey($identity);
                $definition = array_merge([$tokens[$createPosition]], array_slice($tokens, $headerStart, $headerEnd - $headerStart), array_slice($tokens, $position));
                $this->definitionSourceMap = array_merge([$this->statementSourceMap[$createPosition]], array_slice($this->statementSourceMap, $headerStart, $headerEnd - $headerStart), array_slice($this->statementSourceMap, $position));
                $fields = [];
                $indexes = [];
                $this->parse_field_or_key($definition, $fields, $indexes);
                $tables[$key] ??= $identity;
                foreach ($indexes as $index) {
                    if ($conditional || $replace) {
                        $existingPosition = null;
                        $hasUnnamedIndex = false;
                        foreach ($tables[$key]['indexes'] ?? [] as $indexPosition => $existing) {
                            if (in_array($existing['type'], ['PRIMARY', 'FOREIGN', 'CHECK'], true)) {
                                continue;
                            }
                            $name = $existing['name'] ?? $existing['constraint_name'] ?? null;
                            $hasUnnamedIndex = $hasUnnamedIndex || $name === null;
                            if ($name !== null && strcasecmp($name, $index['name'] ?? '') === 0) {
                                $existingPosition = $indexPosition;
                                break;
                            }
                        }
                        if ($existingPosition !== null) {
                            if ($conditional) {
                                continue;
                            }
                            array_splice($tables[$key]['indexes'], $existingPosition, 1);
                        } elseif ($hasUnnamedIndex) {
                            $tables[$key]['issues'][] = new Issue('The index declaration cannot be resolved against server-generated index names.', $this->line($this->statementSourceMap[0][0]));
                            continue;
                        }
                    }
                    $tables[$key]['indexes'][] = $index;
                }
            } elseif ($command === 'CREATE') {
                // Stop at another object kind so VIEW inside a routine is never a declaration.
                for ($position = 1; isset($tokens[$position]); ++$position) {
                    $kind = strtoupper($tokens[$position]);
                    if ($kind === 'VIEW') {
                        ++$position;
                        $conditional = ($tokens[$position] ?? null) === 'IF NOT EXISTS';
                        $position += (int) $conditional;
                        $view = $this->readIdentity($tokens, $position);
                        $view['database'] ??= $database;
                        if (($tokens[$position] ?? null) === '(') {
                            $depth = 0;
                            do {
                                $token = $tokens[$position++] ?? null;
                                if ($token === null) {
                                    throw $this->syntaxError('Unterminated view column list', $this->statementSourceMap[0][0]);
                                }
                                $depth += ($token === '(' ? 1 : ($token === ')' ? -1 : 0));
                            } while ($depth > 0);
                        }
                        if (strtoupper($tokens[$position++] ?? '') !== 'AS' || ! isset($tokens[$position])) {
                            throw $this->syntaxError('Expected a view query', $this->statementSourceMap[0][0]);
                        }
                        $queryEnd = count($tokens);
                        // The check option belongs to the view declaration, not its query.
                        if ($this->next_tokens($tokens, $queryEnd - 2, 'CHECK', 'OPTION')) {
                            $suffix = $queryEnd - 3;
                            if (in_array(strtoupper($tokens[$suffix]), ['CASCADED', 'LOCAL'], true)) {
                                --$suffix;
                            }
                            if (strtoupper($tokens[$suffix]) === 'WITH') {
                                $queryEnd = $suffix;
                            }
                        }
                        if ($queryEnd <= $position) {
                            throw $this->syntaxError('Expected a view query', $this->statementSourceMap[0][0]);
                        }
                        $first = $this->statementSourceMap[$position];
                        $last = $this->statementSourceMap[$queryEnd - 1];
                        $view['definition'] = substr($sql, $first[0], $last[0] + $last[1] - $first[0]);
                        $key = $this->generateTableKey($view);
                        if (! $conditional || ! isset($views[$key])) {
                            $views[$key] = $view;
                            unset($tables[$key]);
                        }
                        break;
                    }
                    if (in_array($kind, ['INDEX', 'UNIQUE INDEX', 'FULLTEXT INDEX', 'SPATIAL INDEX', 'VECTOR INDEX'], true)) {
                        $this->issues[] = new Issue('The CREATE INDEX statement could not be applied to the schema definition.', $this->line($this->statementSourceMap[0][0]));
                        break;
                    }
                    if (in_array($kind, ['TABLE', 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT', 'DATABASE', 'USER'], true)) {
                        break;
                    }
                }
            } elseif ($command === 'ALTER' && strtoupper($tokens[1] ?? '') === 'TABLE') {
                $position = 2;
                $identity = $this->readIdentity($tokens, $position);
                $identity['database'] ??= $database;
                // Dump-time index toggles do not change the represented schema facts.
                if ($this->next_tokens($tokens, $position, 'ENABLE', 'KEYS') || $this->next_tokens($tokens, $position, 'DISABLE', 'KEYS')) {
                    continue;
                }
                $key = $this->generateTableKey($identity);
                $tables[$key] ??= $identity;
                $renamesTable = false;
                for ($cursor = $position; isset($tokens[$cursor]); ++$cursor) {
                    if (strtoupper($tokens[$cursor]) === 'RENAME'
                        && ! in_array(strtoupper($tokens[$cursor + 1] ?? ''), ['COLUMN', 'INDEX', 'KEY'], true)) {
                        $renamesTable = true;
                        break;
                    }
                }
                if ($renamesTable) {
                    $this->issues[] = new Issue('The ALTER TABLE statement could change a table identity.', $this->line($this->statementSourceMap[0][0]));
                } else {
                    $tables[$key]['issues'][] = new Issue('The ALTER TABLE statement could not be applied to the schema definition.', $this->line($this->statementSourceMap[0][0]));
                }
            } elseif ($command === 'RENAME') {
                $this->issues[] = new Issue('The RENAME statement could change table identities.', $this->line($this->statementSourceMap[0][0]));
            }

            // Temporary declarations are session objects and must not replace
            // same-named persistent tables in the schema snapshot.
        }

        return ['tables' => $tables, 'views' => $views];
    }

    /**
     * Split statements without retaining another complete token tree.
     *
     * @param list<string> $tokens
     * @param list<array{int, int}> $sourceMap
     * @return Generator<int, array{tokens: list<string>, map: list<array{int, int}>, sql: string}>
     */
    private function statements(array $tokens, string $sql, array $sourceMap): Generator
    {
        $delimiter = ';';
        $start = 0;
        $count = count($tokens);

        for ($position = 0; $position <= $count; ++$position) {
            $token = $tokens[$position] ?? null;
            if ($position === $start && $token !== null && preg_match('/^DELIMITER\s+(\S+)\s*$/iD', $token, $matches)) {
                $delimiter = $matches[1];
                $start = $position + 1;
                continue;
            }
            if ($token !== null && $token !== $delimiter) {
                continue;
            }
            if ($position > $start) {
                $first = $sourceMap[$start];
                $last = $sourceMap[$token === null ? $position - 1 : $position];
                yield [
                    'tokens' => array_slice($tokens, $start, $position - $start),
                    'map' => array_slice($sourceMap, $start, $position - $start),
                    'sql' => substr($sql, $first[0], $last[0] + $last[1] - $first[0]),
                ];
            }
            $start = $position + 1;
        }
    }

    /**
     * Index identities without conflating dots inside quoted names.
     *
     * @param array{name: string, database: ?string} $table
     */
    private function generateTableKey(array $table): string
    {
        return ($table['database'] === null ? 'n:' : 's:' . strlen($table['database']) . ':' . $table['database']) . $table['name'];
    }

    /**
     * Read a separate database and object name.
     *
     * @param list<string> $tokens
     * @return array{name: string, database: ?string}
     */
    private function readIdentity(array $tokens, int &$position): array
    {
        if (! isset($tokens[$position])) {
            throw $this->syntaxError('Expected an object name', $this->statementSourceMap[0][0]);
        }
        $name = $this->decode_identifier($tokens[$position++]);
        $database = null;
        if (($tokens[$position] ?? null) === '.') {
            ++$position;
            if (! isset($tokens[$position])) {
                throw $this->syntaxError('Expected a qualified object name', $this->statementSourceMap[0][0]);
            }
            $database = $name;
            $name = $this->decode_identifier($tokens[$position++]);
        }

        return ['name' => $name, 'database' => $database];
    }

    /**
     * Read a table declaration.
     *
     * @param list<string> $tokens
     * @return Table
     */
    public function parse_create_table(array $tokens, int $i): array
    {
        if (($tokens[$i] ?? null) === 'IF NOT EXISTS') {
            ++$i;
        }

        #
        # name
        #

        $identity = $this->readIdentity($tokens, $i);
        $database = $identity['database'];
        $name = $identity['name'];

        #
        # CREATE TABLE x LIKE y
        #

        if ($this->next_tokens($tokens, $i, 'LIKE')) {
            ++$i;
            $reference = $this->readIdentity($tokens, $i);

            return [
                'name' => $name,
                'database' => $database,
                'like' => $reference['name'],
                'like_database' => $reference['database'],
            ];
        }

        #
        # create_definition
        #

        $fields = [];
        $indexes = [];
        $issues = [];

        if ($this->next_tokens($tokens, $i, '(')) {
            ++$i;
            $ret = $this->parse_create_definition($tokens, $i);
            $fields = $ret['fields'];
            $indexes = $ret['indexes'];
            $issues = $ret['issues'];
        }

        $props = $this->parse_table_props($tokens, $i);

        $table = [
            'name' => $name,
            'database' => $database,
            'fields' => $fields,
            'indexes' => $indexes,
            'props' => $props,
        ];
        if ($issues !== []) {
            $table['issues'] = $issues;
        }

        // Decode these while the declaration's mode is active, before later SET statements restore it.
        if (isset($props['COMMENT'])) {
            $table['comment'] = $this->decode_value($props['COMMENT']);
        }
        if (isset($props['ENGINE'])) {
            $table['engine'] = $this->decode_value($this->decode_identifier($props['ENGINE']));
        }

        if ($i <= count($tokens)) {
            $table['more'] = array_slice($tokens, $i);
        }

        return $table;
    }

    /**
     * Match the next tokens without consuming them.
     *
     * @param list<string> $tokens
     */
    public function next_tokens(array $tokens, int $i, string ...$expected): bool
    {
        foreach ($expected as $v) {
            if ($i >= count($tokens)) {
                return false;
            }
            if (strtoupper($tokens[$i]) != $v) {
                return false;
            }
            ++$i;
        }
        return true;
    }

    /**
     * Read the table's ordered columns and constraints.
     *
     * @param list<string> $tokens
     * @return array{fields: list<Field>, indexes: list<Index>, issues: list<Issue>}
     */
    public function parse_create_definition(array $tokens, int &$i): array
    {
        $fields = [];
        $indexes = [];
        $issues = [];

        while ($i < count($tokens) && $tokens[$i] != ')') {
            $start = $i;
            $these_tokens = $this->slice_until_next_field($tokens, $i);
            if ($these_tokens === []) {
                throw $this->syntaxError('Expected a column or constraint', $this->statementSourceMap[$start][0]);
            }
            $this->definitionSourceMap = array_slice($this->statementSourceMap, $start, count($these_tokens));

            if ($this->next_tokens($these_tokens, 0, 'PERIOD', 'FOR')) {
                // System periods are covered by WITH SYSTEM VERSIONING; named periods add implicit checks.
                if (strtoupper($these_tokens[2] ?? '') !== 'SYSTEM_TIME') {
                    $issues[] = new Issue('Application-time period constraints are not represented.', $this->line($this->statementSourceMap[$start][0]));
                }
                continue;
            }

            $this->parse_field_or_key($these_tokens, $fields, $indexes);
        }

        if (($tokens[$i] ?? null) !== ')') {
            $last = $this->statementSourceMap[array_key_last($this->statementSourceMap)];
            throw $this->syntaxError('Unterminated table definition', $last[0]);
        }
        ++$i;

        return [
            'fields' => $fields,
            'indexes' => $indexes,
            'issues' => $issues,
        ];
    }

    /**
     * Read one column, key or check definition.
     *
     * @param list<string> $tokens
     * @param list<Field> $fields
     * @param list<Index> $indexes
     */
    public function parse_field_or_key(array &$tokens, array &$fields, array &$indexes): void
    {
        #
        # parse a single create_definition
        #

        $has_constraint = false;
        $constraint = null;

        #
        # constraints can come before a few different things
        #

        if ($tokens[0] == 'CONSTRAINT') {
            if (! isset($tokens[1])) {
                throw $this->syntaxError('Expected a constraint definition', $this->definitionSourceMap[0][0]);
            }

            $has_constraint = true;

            if (in_array(strtoupper($tokens[1] ?? ''), ['PRIMARY KEY', 'UNIQUE', 'UNIQUE KEY', 'UNIQUE INDEX', 'FOREIGN KEY', 'CHECK'], true)) {
                array_shift($tokens);
            } else {
                array_shift($tokens);
                $constraint = $this->decode_identifier(array_shift($tokens));
            }
        }

        if ($tokens === []) {
            throw $this->syntaxError('Expected a constraint definition', $this->definitionSourceMap[0][0]);
        }
        switch (strtoupper($tokens[0])) {
            #
            # named indexes
            #
            # INDEX		[index_name]	[index_type] (index_col_name,...) [index_option] ...
            # KEY		[index_name]	[index_type] (index_col_name,...) [index_option] ...
            # UNIQUE	[index_name]	[index_type] (index_col_name,...) [index_option] ...
            # UNIQUE INDEX	[index_name]	[index_type] (index_col_name,...) [index_option] ...
            # UNIQUE KEY	[index_name]	[index_type] (index_col_name,...) [index_option] ...
            #

            case 'INDEX':
            case 'KEY':
            case 'UNIQUE':
            case 'UNIQUE INDEX':
            case 'UNIQUE KEY':
                $index = [
                    'type' => 'INDEX',
                ];

                if ($has_constraint) {
                    $index['constraint'] = true;
                    if (! is_null($constraint)) {
                        $index['constraint_name'] = $constraint;
                    }
                }

                if ($tokens[0] == 'UNIQUE') {
                    $index['type'] = 'UNIQUE';
                }
                if ($tokens[0] == 'UNIQUE INDEX') {
                    $index['type'] = 'UNIQUE';
                }
                if ($tokens[0] == 'UNIQUE KEY') {
                    $index['type'] = 'UNIQUE';
                }

                array_shift($tokens);

                if (isset($tokens[0]) && $tokens[0] != '(' && $tokens[0] != 'USING BTREE' && $tokens[0] != 'USING HASH') {
                    $index['name'] = $this->decode_identifier(array_shift($tokens));
                }

                $this->parse_index_mode($tokens, $index);
                $this->parse_index_columns($tokens, $index);
                $this->parse_index_options($tokens, $index);

                if (count($tokens)) {
                    $index['more'] = $tokens;
                }
                $indexes[] = $index;
                return;
                #
                # PRIMARY KEY [index_type] (index_col_name,...) [index_option] ...
                #

            case 'PRIMARY KEY':
                $index = [
                    'type' => 'PRIMARY',
                ];

                if ($has_constraint) {
                    $index['constraint'] = true;
                    if (! is_null($constraint)) {
                        $index['constraint_name'] = $constraint;
                    }
                }

                array_shift($tokens);

                $this->parse_index_mode($tokens, $index);
                $this->parse_index_columns($tokens, $index);
                $this->parse_index_options($tokens, $index);

                if (count($tokens)) {
                    $index['more'] = $tokens;
                }
                $indexes[] = $index;
                return;
                # FULLTEXT		[index_name] (index_col_name,...) [index_option] ...
                # FULLTEXT INDEX	[index_name] (index_col_name,...) [index_option] ...
                # FULLTEXT KEY		[index_name] (index_col_name,...) [index_option] ...
                # SPATIAL		[index_name] (index_col_name,...) [index_option] ...
                # SPATIAL INDEX		[index_name] (index_col_name,...) [index_option] ...
                # SPATIAL KEY		[index_name] (index_col_name,...) [index_option] ...

            case 'FULLTEXT':
            case 'FULLTEXT INDEX':
            case 'FULLTEXT KEY':
            case 'SPATIAL':
            case 'SPATIAL INDEX':
            case 'SPATIAL KEY':
            case 'VECTOR INDEX':
            case 'VECTOR KEY':
                $index = [
                    'type' => explode(' ', strtoupper($tokens[0]))[0],
                ];

                array_shift($tokens);

                if (isset($tokens[0]) && $tokens[0] != '(') {
                    $index['name'] = $this->decode_identifier(array_shift($tokens));
                }

                $this->parse_index_mode($tokens, $index);
                $this->parse_index_columns($tokens, $index);
                $this->parse_index_options($tokens, $index);

                if (count($tokens)) {
                    $index['more'] = $tokens;
                }
                $indexes[] = $index;
                return;
                # FOREIGN KEY [index_name] (index_col_name,...) reference_definition
                #  reference_definition:
                #    REFERENCES tbl_name (index_col_name,...)
                #      [MATCH FULL | MATCH PARTIAL | MATCH SIMPLE]
                #      [ON DELETE reference_option]
                #      [ON UPDATE reference_option]

            case 'FOREIGN KEY':
                $index = [
                    'type' => 'FOREIGN',
                ];
                if ($has_constraint) {
                    $index['constraint'] = true;
                    if ($constraint !== null) {
                        $index['constraint_name'] = $constraint;
                    }
                }

                array_shift($tokens);

                if (isset($tokens[0]) && $tokens[0] != '(') {
                    $index['name'] = $this->decode_identifier(array_shift($tokens));
                }

                $this->parse_index_columns($tokens, $index);

                if (strtoupper($tokens[0] ?? '') == 'REFERENCES') {
                    array_shift($tokens);
                    $position = 0;
                    $identity = $this->readIdentity($tokens, $position);
                    $index['ref_table'] = $identity['name'];
                    $index['ref_database'] = $identity['database'];
                    array_splice($tokens, 0, $position);

                    $old_cols = $index['cols'];
                    $index['cols'] = [];
                    if (($tokens[0] ?? null) === '(') {
                        $this->parse_index_columns($tokens, $index);
                    }
                    $index['ref_cols'] = $index['cols'];
                    $index['cols'] = $old_cols;

                    if (count($tokens) >= 1 && $tokens[0] == 'MATCH FULL') {
                        $index['ref_match'] = 'FULL';
                        array_shift($tokens);
                    }
                    if (count($tokens) >= 1 && $tokens[0] == 'MATCH PARTIAL') {
                        $index['ref_match'] = 'PARTIAL';
                        array_shift($tokens);
                    }
                    if (count($tokens) >= 1 && $tokens[0] == 'MATCH SIMPLE') {
                        $index['ref_match'] = 'SIMPLE';
                        array_shift($tokens);
                    }

                    // Both action orders are valid; each changes only its own action.
                    while (isset($tokens[0]) && in_array($tokens[0], ['ON DELETE', 'ON UPDATE'], true)) {
                        $action = array_shift($tokens);
                        $value = array_shift($tokens);
                        if ($value === null || ReferentialAction::tryFrom(strtolower($value)) === null) {
                            throw $this->syntaxError("Expected a referential action after {$action}", $this->definitionSourceMap[0][0]);
                        }
                        $index[$action === 'ON DELETE' ? 'ref_on_delete' : 'ref_on_update'] = strtoupper($value);
                    }
                } else {
                    throw $this->syntaxError('Expected REFERENCES in a foreign key', $this->definitionSourceMap[0][0]);
                }

                if (count($tokens)) {
                    $index['more'] = $tokens;
                }
                $indexes[] = $index;
                return;
            case 'CHECK':
                $check = $this->readCheck($tokens, $constraint);
                if ($tokens !== []) {
                    $check['more'] = $tokens;
                }
                $indexes[] = $check;
                return;
        }

        $field = $this->parse_field($tokens);
        foreach ($field['checks'] ?? [] as $check) {
            $indexes[] = $check;
        }
        unset($field['checks']);
        foreach (['primary' => 'PRIMARY', 'unique' => 'UNIQUE'] as $flag => $type) {
            if ($field[$flag] ?? false) {
                $indexes[] = ['type' => $type, 'cols' => [['name' => $field['name']]]];
            }
        }
        $fields[] = $field;
    }

    /**
     * Read a table or column check and its enforcement declaration.
     *
     * @param list<string> $tokens
     * @return Index
     */
    private function readCheck(array &$tokens, ?string $name = null): array
    {
        array_shift($tokens);
        $expressionTokens = $tokens;
        $expression = $this->readExpression($tokens, true);
        $check = [
            'type' => 'CHECK',
            'expression' => $expression,
            'tokens' => array_slice($expressionTokens, 0, count($expressionTokens) - count($tokens)),
            'enforced' => true,
        ];
        if ($name !== null) {
            $check['constraint_name'] = $name;
        }
        if ($this->next_tokens($tokens, 0, 'NOT', 'ENFORCED')) {
            $check['enforced'] = false;
            array_splice($tokens, 0, 2);
        } elseif ($this->next_tokens($tokens, 0, 'ENFORCED')) {
            array_shift($tokens);
        }

        return $check;
    }

    /**
     * Read tokens through a top-level comma or closing parenthesis.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    public function slice_until_next_field(array $tokens, int &$i): array
    {
        $out = [];
        $stack = 0;

        while ($i < count($tokens)) {
            $next = $tokens[$i];
            if ($next == '(') {
                ++$stack;
                $out[] = $tokens[$i++];
            } elseif ($next == ')') {
                if ($stack) {
                    --$stack;
                    $out[] = $tokens[$i++];
                } else {
                    return $out;
                }
            } elseif ($next == ',') {
                if ($stack) {
                    $out[] = $tokens[$i++];
                } else {
                    ++$i;
                    return $out;
                }
            } else {
                $out[] = $tokens[$i++];
            }
        }

        return $out;
    }

    /**
     * Read a column's type and options.
     *
     * @param list<string> $tokens
     * @return Field
     */
    public function parse_field(array $tokens): array
    {
        if (count($tokens) < 2 || in_array(strtoupper($tokens[1]), ['NOT', 'NOT NULL', 'NULL', 'DEFAULT', 'PRIMARY KEY', 'UNIQUE', 'CHECK', 'REFERENCES', 'AS', 'GENERATED', 'COMMENT'], true)) {
            throw $this->syntaxError('Expected a column type', $this->definitionSourceMap[0][0]);
        }

        $f = [
            'name' => $this->decode_identifier(array_shift($tokens)),
            'type' => strtoupper(array_shift($tokens)),
        ];

        switch ($f['type']) {
            case 'SERIAL':
                // SERIAL expands to column and key properties rather than a distinct stored type.
                $f['type'] = 'BIGINT';
                $f['unsigned'] = true;
                $f['null'] = false;
                $f['auto_increment'] = true;
                $f['unique'] = true;
                break;
                # DATE
            case 'DATE':
            case 'TINYBLOB':
            case 'BLOB':
            case 'MEDIUMBLOB':
            case 'LONGBLOB':
            case 'JSON':
            case 'UUID':
            case 'GEOMETRY':
            case 'POINT':
            case 'LINESTRING':
            case 'POLYGON':
            case 'MULTIPOINT':
            case 'MULTILINESTRING':
            case 'MULTIPOLYGON':
            case 'GEOMETRYCOLLECTION':
            case 'GEOMCOLLECTION':
            case 'INET4':
            case 'INET6':
            case 'BOOLEAN':
            case 'BOOL':
                # nothing more to read
                break;
                # TIME[(fsp)]
            case 'TIME':
            case 'TIMESTAMP':
            case 'DATETIME':
                # optional fractional seconds precision
                if (count($tokens) >= 3) {
                    if ($tokens[0] == '(' && $tokens[2] == ')') {
                        $f['fsp'] = $tokens[1];
                        array_shift($tokens);
                        array_shift($tokens);
                        array_shift($tokens);
                    }
                }
                break;
                # TINYINT[(length)] [UNSIGNED] [ZEROFILL]
            case 'TINYINT':
            case 'SMALLINT':
            case 'MEDIUMINT':
            case 'INT':
            case 'INTEGER':
            case 'BIGINT':
                $this->parse_field_length($tokens, $f);
                $this->parse_field_unsigned($tokens, $f);
                $this->parse_field_zerofill($tokens, $f);
                break;
                # REAL[(length,decimals)] [UNSIGNED] [ZEROFILL]
            case 'REAL':
            case 'DOUBLE':
            case 'DOUBLE PRECISION':
            case 'FLOAT':
                $this->parse_field_length_decimals($tokens, $f);
                $this->parse_field_length($tokens, $f);
                $this->parse_field_unsigned($tokens, $f);
                $this->parse_field_zerofill($tokens, $f);
                break;
                # DECIMAL[(length[,decimals])] [UNSIGNED] [ZEROFILL]
            case 'DECIMAL':
            case 'NUMERIC':
            case 'DEC':
            case 'FIXED':
                $this->parse_field_length_decimals($tokens, $f);
                $this->parse_field_length($tokens, $f);
                $this->parse_field_unsigned($tokens, $f);
                $this->parse_field_zerofill($tokens, $f);
                break;
                # BIT[(length)]
                # BINARY[(length)]
            case 'BIT':
            case 'BINARY':
            case 'YEAR':
            case 'VECTOR':
                $this->parse_field_length($tokens, $f);
                break;
                # VARBINARY(length)
            case 'VARBINARY':
                $this->parse_field_length($tokens, $f);
                break;
                # CHAR[(length)] [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
            case 'CHAR':
                $this->parse_field_binary($tokens, $f);
                $this->parse_field_length($tokens, $f);
                break;
                # VARCHAR(length) [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
            case 'VARCHAR':
            case 'CHARACTER VARYING':
                $this->parse_field_binary($tokens, $f);
                $this->parse_field_length($tokens, $f);
                break;
                # TINYTEXT   [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
                # TEXT       [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
                # MEDIUMTEXT [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
                # LONGTEXT   [BINARY] [CHARACTER SET charset_name] [COLLATE collation_name]
            case 'TINYTEXT':
            case 'TEXT':
            case 'MEDIUMTEXT':
            case 'LONGTEXT':
                $this->parse_field_binary($tokens, $f);
                break;
                # ENUM(value1,value2,value3,...) [CHARACTER SET charset_name] [COLLATE collation_name]
                # SET (value1,value2,value3,...) [CHARACTER SET charset_name] [COLLATE collation_name]
            case 'ENUM':
            case 'SET':
                $f['values'] = $this->parse_value_list($tokens);
                break;
            default:
                if (($tokens[0] ?? null) === '(') {
                    array_shift($tokens);
                    $f['modifiers'] = [];
                    $position = 0;

                    while (isset($tokens[$position]) && $tokens[$position] !== ')') {
                        $start = $position;
                        $modifier = $this->slice_until_next_field($tokens, $position);
                        $offset = count($this->definitionSourceMap) - count($tokens) + $start;
                        $f['modifiers'][] = $this->definitionSpan($offset, count($modifier));
                    }

                    if (($tokens[$position] ?? null) !== ')') {
                        throw $this->syntaxError('Unterminated type modifiers', $this->definitionSourceMap[0][0]);
                    }

                    array_splice($tokens, 0, $position + 1);
                }
        }

        while ($tokens !== []) {
            $option = strtoupper($tokens[0]);

            switch ($option) {
                case 'NULL':
                case 'NOT NULL':
                    $f['null'] = $option === 'NULL';
                    array_shift($tokens);
                    break;
                case 'DEFAULT':
                case 'ON UPDATE':
                    array_shift($tokens);
                    // Defaults are SQL expressions, not decoded strings or nullability declarations.
                    $f[$option === 'DEFAULT' ? 'default' : 'on_update'] = $this->readExpression($tokens);
                    break;
                case 'AUTO_INCREMENT':
                case 'UNSIGNED':
                case 'ZEROFILL':
                case 'BINARY':
                    $f[strtolower($option)] = true;
                    array_shift($tokens);
                    break;
                case 'COMMENT':
                case 'CHARACTER SET':
                case 'COLLATE':
                    array_shift($tokens);
                    $value = array_shift($tokens);
                    if ($value === null) {
                        throw $this->syntaxError("Expected a value after {$option}", $this->definitionSourceMap[0][0]);
                    }
                    $key = match ($option) {
                        'COMMENT' => 'comment',
                        'COLLATE' => 'collation',
                        default => 'character_set',
                    };
                    $f[$key] = match ($option) {
                        'COMMENT' => $this->decode_value($value),
                        'COLLATE' => $value,
                        default => $this->decode_identifier($value),
                    };
                    break;
                case 'PRIMARY KEY':
                case 'KEY':
                    $f['primary'] = true;
                    array_shift($tokens);
                    break;
                case 'UNIQUE':
                case 'UNIQUE KEY':
                    $f['unique'] = true;
                    array_shift($tokens);
                    break;
                case 'GENERATED':
                    array_shift($tokens);
                    if (strtoupper($tokens[0] ?? '') === 'ALWAYS') {
                        array_shift($tokens);
                    }
                    if (strtoupper($tokens[0] ?? '') !== 'AS') {
                        throw $this->syntaxError('Expected AS in a generated column', $this->definitionSourceMap[0][0]);
                    }
                    // Fall through to the shared AS expression reader.
                    // no break
                case 'AS':
                    array_shift($tokens);
                    if ($this->next_tokens($tokens, 0, 'ROW', 'START') || $this->next_tokens($tokens, 0, 'ROW', 'END')) {
                        array_splice($tokens, 0, 2);
                        break;
                    }
                    $f['generation'] = $this->readExpression($tokens, true);
                    $f['generation_type'] = 'virtual';
                    break;
                case 'VIRTUAL':
                case 'STORED':
                case 'PERSISTENT':
                    $f['generation_type'] = $option === 'VIRTUAL' ? 'virtual' : 'stored';
                    array_shift($tokens);
                    break;
                case 'INVISIBLE':
                case 'VISIBLE':
                    $f['invisible'] = $option === 'INVISIBLE';
                    array_shift($tokens);
                    break;
                case 'COLUMN_FORMAT':
                case 'STORAGE':
                case 'SRID':
                case 'REF_SYSTEM_ID':
                case 'ENGINE_ATTRIBUTE':
                case 'SECONDARY_ENGINE_ATTRIBUTE':
                    $valuePosition = ($tokens[1] ?? null) === '=' ? 2 : 1;
                    if (! isset($tokens[$valuePosition]) || in_array($tokens[$valuePosition], ['(', ')', ',', '='], true)) {
                        throw $this->syntaxError("Expected a value after {$option}", $this->definitionSourceMap[0][0]);
                    }
                    array_splice($tokens, 0, $valuePosition + 1);
                    break;
                case 'CHECK':
                    $f['checks'][] = $this->readCheck($tokens);
                    break;
                case 'CONSTRAINT':
                    array_shift($tokens);
                    $name = null;
                    if ($tokens === []) {
                        throw $this->syntaxError('Expected a column CHECK constraint', $this->definitionSourceMap[0][0]);
                    }
                    if (strtoupper($tokens[0] ?? '') !== 'CHECK') {
                        $name = $this->decode_identifier(array_shift($tokens));
                    }
                    if (strtoupper($tokens[0] ?? '') !== 'CHECK') {
                        throw $this->syntaxError('Expected a column CHECK constraint', $this->definitionSourceMap[0][0]);
                    }
                    $f['checks'][] = $this->readCheck($tokens, $name);
                    break;
                case 'COMPRESSED':
                    if (($tokens[1] ?? null) !== '=') {
                        array_shift($tokens);
                        break;
                    }
                    // A compression method uses the same assignment form as other engine options.
                    // no break
                default:
                    $offset = $this->definitionSourceMap[count($this->definitionSourceMap) - count($tokens)][0] ?? null;
                    if ($this->readAssignedOption($tokens, 0, $offset) === null) {
                        break 2;
                    }
                    array_splice($tokens, 0, 3);
                    break;
            }
        }

        if (count($tokens)) {
            $f['more'] = $tokens;
        }

        return $f;
    }

    /**
     * Read a literal, named expression or balanced SQL expression.
     *
     * @param list<string> $tokens
     */
    private function readExpression(array &$tokens, bool $parenthesized = false): string
    {
        $start = count($this->definitionSourceMap) - count($tokens);

        if ($tokens === [] || ($parenthesized && $tokens[0] !== '(')) {
            throw $this->syntaxError('Expected a SQL expression', $this->definitionSourceMap[max(0, $start - 1)][0]);
        }

        $position = in_array($tokens[0], ['+', '-'], true) ? 1 : 0;
        if (! isset($tokens[$position])) {
            throw $this->syntaxError('Expected an expression after the sign', $this->definitionSourceMap[$start][0]);
        }
        if ($tokens[$position] !== '(') {
            $prefix = $tokens[$position];
            ++$position;

            if ((strtoupper($prefix) === 'N' || str_starts_with($prefix, '_'))
                && isset($tokens[$position]) && in_array($tokens[$position][0], ["'", '"'], true)) {
                ++$position;
            }
        }

        if (($tokens[$position] ?? null) === '(') {
            $depth = 0;
            do {
                $token = $tokens[$position++] ?? null;
                if ($token === null) {
                    throw $this->syntaxError('Unterminated SQL expression', $this->definitionSourceMap[$start][0]);
                }
                if ($token === '(') {
                    ++$depth;
                } elseif ($token === ')') {
                    --$depth;
                }
            } while ($depth > 0);
        }

        $expression = $this->definitionSpan($start, $position);
        array_splice($tokens, 0, $position);

        return $expression;
    }

    /**
     * Preserve SQL text between the first and last consumed tokens.
     */
    private function definitionSpan(int $start, int $length): string
    {
        $first = $this->definitionSourceMap[$start];
        $last = $this->definitionSourceMap[$start + $length - 1];

        return substr($this->sql, $first[0], $last[0] + $last[1] - $first[0]);
    }

    /**
     * Read table options using their canonical keyword names.
     *
     * @param list<string> $tokens
     * @return array<string, string>
     */
    public function parse_table_props(array $tokens, int &$i): array
    {
        $alt_names = [
            'CHARACTER SET' => 'CHARSET',
            'DEFAULT CHARACTER SET' => 'CHARSET',
            'DEFAULT CHARSET' => 'CHARSET',
            'DEFAULT COLLATE' => 'COLLATE',
        ];

        $props = [];

        while ($i < count($tokens)) {
            $optionPosition = $i;

            switch (strtoupper($tokens[$i])) {
                case 'ENGINE':
                case 'AUTO_INCREMENT':
                case 'AVG_ROW_LENGTH':
                case 'CHECKSUM':
                case 'COMMENT':
                case 'CONNECTION':
                case 'DELAY_KEY_WRITE':
                case 'INSERT_METHOD':
                case 'KEY_BLOCK_SIZE':
                case 'MAX_ROWS':
                case 'MIN_ROWS':
                case 'PACK_KEYS':
                case 'PASSWORD':
                case 'ROW_FORMAT':
                case 'COLLATE':
                case 'CHARSET':
                case 'DATA DIRECTORY':
                case 'INDEX DIRECTORY':
                case 'TABLESPACE':
                case 'STORAGE':
                    $prop = strtoupper($tokens[$i++]);
                    if (isset($tokens[$i]) && $tokens[$i] == '=') {
                        ++$i;
                    }
                    if (! isset($tokens[$i]) || in_array($tokens[$i], ['(', ')', ',', '='], true)) {
                        throw $this->syntaxError("Expected a value for table option {$prop}", $this->statementSourceMap[$optionPosition][0] ?? null);
                    }
                    $props[$prop] = $tokens[$i++];
                    if (isset($tokens[$i]) && $tokens[$i] == ',') {
                        ++$i;
                    }
                    break;
                case 'CHARACTER SET':
                case 'DEFAULT COLLATE':
                case 'DEFAULT CHARACTER SET':
                case 'DEFAULT CHARSET':
                    $prop = $alt_names[strtoupper($tokens[$i++])];
                    if (isset($tokens[$i]) && $tokens[$i] == '=') {
                        ++$i;
                    }
                    if (! isset($tokens[$i]) || in_array($tokens[$i], ['(', ')', ',', '='], true)) {
                        throw $this->syntaxError("Expected a value for table option {$prop}", $this->statementSourceMap[$optionPosition][0] ?? null);
                    }
                    $props[$prop] = $tokens[$i++];
                    if (isset($tokens[$i]) && $tokens[$i] == ',') {
                        ++$i;
                    }
                    break;
                default:
                    $assignment = $this->readAssignedOption($tokens, $i, $this->statementSourceMap[$i][0] ?? null);
                    if ($assignment === null) {
                        break 2;
                    }
                    $props[$assignment[0]] = $assignment[1];
                    $i += 3;
                    if (($tokens[$i] ?? null) === ',') {
                        ++$i;
                    }
                    break;
            }
        }

        return $props;
    }

    /**
     * Read a scalar engine-option assignment without consuming an unknown complex clause.
     *
     * @param list<string> $tokens
     * @return null|array{string, string}
     */
    private function readAssignedOption(array $tokens, int $position, ?int $offset): ?array
    {
        if (($tokens[$position + 1] ?? null) !== '=') {
            return null;
        }

        $name = $tokens[$position];
        $quoted = $name[0] === '`' || ($name[0] === '"' && ($this->sqlMode & self::ANSI_QUOTES) !== 0);
        if (! $quoted && ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)) {
            return null;
        }

        $value = $tokens[$position + 2] ?? null;
        if ($value === '(') {
            return null;
        }
        if ($value === null || in_array($value, [',', ')', '='], true)) {
            throw $this->syntaxError("Expected a value for option {$name}", $offset);
        }

        return [strtoupper($this->decode_identifier($name)), $value];
    }

    # Given the source map, extract the tokens from the original sql,
    # Along the way, simplify parsing by merging certain tokens when
    # they occur next to each other. MySQL treats these productions
    # equally: 'UNIQUE|UNIQUE INDEX|UNIQUE KEY' and if they are
    # all always a single token it makes parsing easier.

    /**
     * Collapse compound keywords while retaining their source spans.
     *
     * @param list<array{int, int}> $source_map
     * @return list<string>
     */
    public function _extract_tokens(string $sql, array &$source_map): array
    {
        $lists = [
            'FULLTEXT INDEX',
            'FULLTEXT KEY',
            'SPATIAL INDEX',
            'SPATIAL KEY',
            'VECTOR INDEX',
            'VECTOR KEY',
            'FOREIGN KEY',
            'USING BTREE',
            'USING HASH',
            'PRIMARY KEY',
            'UNIQUE INDEX',
            'UNIQUE KEY',
            'CREATE TABLE',
            'CREATE TEMPORARY TABLE',
            'CREATE OR REPLACE TABLE',
            'CREATE OR REPLACE TEMPORARY TABLE',
            'DATA DIRECTORY',
            'INDEX DIRECTORY',
            'DEFAULT CHARACTER SET',
            'CHARACTER SET',
            'DEFAULT CHARSET',
            'DEFAULT COLLATE',
            'IF NOT EXISTS',
            'NOT NULL',
            'WITH PARSER',
            'MATCH FULL',
            'MATCH PARTIAL',
            'MATCH SIMPLE',
            'ON DELETE',
            'ON UPDATE',
            'SET NULL',
            'NO ACTION',
            'SET DEFAULT',
            'DOUBLE PRECISION',
            'CHARACTER VARYING',
        ];

        $singles = [
            'NULL',
            'CONSTRAINT',
            'INDEX',
            'KEY',
            'UNIQUE',
        ];

        $maps = [];
        foreach ($lists as $l) {
            $a = explode(' ', $l);
            $maps[$a[0]][] = $a;
        }
        $smap = [];
        foreach ($singles as $s) {
            $smap[$s] = 1;
        }

        $out = [];
        $out_map = [];

        $i = 0;
        $len = count($source_map);
        while ($i < $len) {
            $token = substr($sql, $source_map[$i][0], $source_map[$i][1]);
            $tokenUpper = strtoupper($token);
            if (isset($maps[$tokenUpper])) {
                $found = false;
                foreach ($maps[$tokenUpper] as $list) {
                    $fail = false;
                    foreach ($list as $k => $v) {
                        if (! isset($source_map[$k + $i])) {
                            $fail = true;
                            break;
                        }
                        $next = strtoupper(substr($sql, $source_map[$k + $i][0], $source_map[$k + $i][1]));
                        if ($v != $next) {
                            $fail = true;
                            break;
                        }
                    }
                    if (! $fail) {
                        $out[] = implode(' ', $list);

                        # Extend the length of the first token to include everything
                        # up through the last in the sequence.
                        $j = $i + count($list) - 1;
                        $out_map[] = [$source_map[$i][0], ($source_map[$j][0] - $source_map[$i][0]) + $source_map[$j][1]];

                        $i = $j + 1;
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    continue;
                }
            }
            if (isset($smap[$tokenUpper])) {
                $out[] = $tokenUpper;
                $out_map[] = $source_map[$i];
                ++$i;
                continue;
            }
            $out[] = $token;
            $out_map[] = $source_map[$i];
            ++$i;
        }

        $source_map = $out_map;
        return $out;
    }

    /**
     * Read an index's storage method.
     *
     * @param list<string> $tokens
     * @param Index $index
     */
    public function parse_index_mode(array &$tokens, array &$index): void
    {
        if (count($tokens) >= 1) {
            if ($tokens[0] == 'USING BTREE') {
                $index['mode'] = 'BTREE';
                array_shift($tokens);
                return;
            }
            if ($tokens[0] == 'USING HASH') {
                $index['mode'] = 'HASH';
                array_shift($tokens);
                return;
            }
        }
    }

    /**
     * Read ordered column or expression keys.
     *
     * @param list<string> $tokens
     * @param Index $index
     */
    public function parse_index_columns(array &$tokens, array &$index): void
    {
        if (($tokens[0] ?? null) !== '(') {
            throw $this->syntaxError('Expected index columns', $this->definitionSourceMap[0][0]);
        }
        array_shift($tokens);

        while ($tokens !== []) {
            $start = count($this->definitionSourceMap) - count($tokens);
            if ($tokens[0] === '(') {
                $column = ['expression' => $this->readExpression($tokens, true)];
            } else {
                $column = ['name' => $this->decode_identifier(array_shift($tokens))];
                if (($tokens[0] ?? null) === '(' && ($tokens[2] ?? null) === ')') {
                    $column['length'] = $tokens[1];
                    array_splice($tokens, 0, 3);
                }
                if ($this->next_tokens($tokens, 0, 'WITHOUT', 'OVERLAPS')) {
                    $column = ['expression' => $this->definitionSpan($start, 3)];
                    array_splice($tokens, 0, 2);
                }
            }
            if (in_array(strtoupper($tokens[0] ?? ''), ['ASC', 'DESC'], true)) {
                $column['direction'] = strtoupper(array_shift($tokens));
            }
            $index['cols'][] = $column;

            if (($tokens[0] ?? null) === ')') {
                array_shift($tokens);
                return;
            }
            if (($tokens[0] ?? null) !== ',') {
                break;
            }
            array_shift($tokens);
        }

        throw $this->syntaxError('Unterminated or invalid index columns', $this->definitionSourceMap[0][0]);
    }

    /**
     * Read options following index keys.
     *
     * @param list<string> $tokens
     * @param Index $index
     */
    public function parse_index_options(array &$tokens, array &$index): void
    {
        # index_option:
        #    KEY_BLOCK_SIZE [=] value
        #  | index_type
        #  | WITH PARSER parser_name
        #  | COMMENT 'string'

        while ($tokens !== []) {
            $option = strtoupper($tokens[0]);
            $offset = $this->definitionSourceMap[count($this->definitionSourceMap) - count($tokens)][0] ?? null;

            if (in_array($option, ['KEY_BLOCK_SIZE', 'WITH PARSER', 'COMMENT', 'ENGINE_ATTRIBUTE', 'SECONDARY_ENGINE_ATTRIBUTE'], true)) {
                $valuePosition = ! in_array($option, ['WITH PARSER', 'COMMENT'], true) && ($tokens[1] ?? null) === '=' ? 2 : 1;
                $value = $tokens[$valuePosition] ?? null;
                if ($value === null || in_array($value, ['(', ')', ',', '='], true)) {
                    throw $this->syntaxError("Expected a value after {$option}", $offset);
                }
                if ($option === 'KEY_BLOCK_SIZE') {
                    $index['key_block_size'] = $value;
                } elseif ($option === 'WITH PARSER') {
                    $index['parser'] = $value;
                } elseif ($option === 'COMMENT') {
                    $index['comment'] = $this->decode_value($value);
                }
                array_splice($tokens, 0, $valuePosition + 1);
                continue;
            }

            if (in_array($option, ['VISIBLE', 'INVISIBLE', 'IGNORED'], true)) {
                array_shift($tokens);
                continue;
            }
            if ($this->next_tokens($tokens, 0, 'NOT', 'IGNORED')) {
                array_splice($tokens, 0, 2);
                continue;
            }
            if (! isset($index['mode'])) {
                $this->parse_index_mode($tokens, $index);
                if (isset($index['mode'])) {
                    continue;
                }
            }
            if ($this->readAssignedOption($tokens, 0, $offset) !== null) {
                array_splice($tokens, 0, 3);
                continue;
            }

            break;
        }
    }

    #
    # helper functions for parsing bits of field definitions
    #

    /**
     * Read a single type length modifier.
     *
     * @param list<string> $tokens
     * @param Field $f
     */
    public function parse_field_length(array &$tokens, array &$f): void
    {
        if (count($tokens) >= 3) {
            if ($tokens[0] == '(' && $tokens[2] == ')') {
                $f['length'] = $tokens[1];
                array_shift($tokens);
                array_shift($tokens);
                array_shift($tokens);
            }
        }
    }

    /**
     * Read numeric precision and scale.
     *
     * @param list<string> $tokens
     * @param Field $f
     */
    public function parse_field_length_decimals(array &$tokens, array &$f): void
    {
        if (count($tokens) >= 5) {
            if ($tokens[0] == '(' && $tokens[2] == ',' && $tokens[4] == ')') {
                $f['length'] = $tokens[1];
                $f['decimals'] = $tokens[3];
                array_shift($tokens);
                array_shift($tokens);
                array_shift($tokens);
                array_shift($tokens);
                array_shift($tokens);
            }
        }
    }

    /**
     * Read the binary string modifier.
     *
     * @param list<string> $tokens
     * @param Field $f
     */
    public function parse_field_binary(array &$tokens, array &$f): void
    {
        if (count($tokens) >= 1) {
            if (strtoupper($tokens[0]) == 'BINARY') {
                $f['binary'] = true;
                array_shift($tokens);
            }
        }
    }

    /**
     * Read the unsigned numeric modifier.
     *
     * @param list<string> $tokens
     * @param Field $f
     */
    public function parse_field_unsigned(array &$tokens, array &$f): void
    {
        if (count($tokens) >= 1) {
            if (strtoupper($tokens[0]) == 'UNSIGNED') {
                $f['unsigned'] = true;
                array_shift($tokens);
            }
        }
    }

    /**
     * Read the zero-fill numeric modifier.
     *
     * @param list<string> $tokens
     * @param Field $f
     */
    public function parse_field_zerofill(array &$tokens, array &$f): void
    {
        if (count($tokens) >= 1) {
            if (strtoupper($tokens[0]) == 'ZEROFILL') {
                $f['zerofill'] = true;
                array_shift($tokens);
            }
        }
    }

    /**
     * Read decoded enum or set labels.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    public function parse_value_list(array &$tokens): array
    {
        if (($tokens[0] ?? null) !== '(') {
            throw $this->syntaxError('Expected enum or set labels', $this->definitionSourceMap[0][0]);
        }

        $values = [];
        $position = 1;
        while (isset($tokens[$position])) {
            if ($tokens[$position] === ')') {
                array_splice($tokens, 0, $position + 1);
                return $values;
            }

            $values[] = $this->decode_value($tokens[$position++]);

            if (($tokens[$position] ?? null) === ')') {
                array_splice($tokens, 0, $position + 1);
                return $values;
            }
            if (($tokens[$position++] ?? null) !== ',') {
                break;
            }
        }

        throw $this->syntaxError('Unterminated enum or set labels', $this->definitionSourceMap[0][0]);
    }

    /**
     * Decode a MySQL identifier.
     */
    public function decode_identifier(string $token): string
    {
        $quoted = str_starts_with($token, '`')
            || (str_starts_with($token, '"') && ($this->sqlMode & self::ANSI_QUOTES) !== 0);

        return $quoted
            ? str_replace($token[0] . $token[0], $token[0], substr($token, 1, -1))
            : $token;
    }

    /**
     * Decode a quoted string without interpreting SQL expressions.
     */
    public function decode_value(string $token): string
    {
        if (! str_starts_with($token, "'") && ! str_starts_with($token, '"')) {
            return $token;
        }

        $result = '';
        $quote = $token[0];
        $length = strlen($token) - 1;

        for ($position = 1; $position < $length; ++$position) {
            if ($token[$position] === '\\' && ($this->sqlMode & self::NO_BACKSLASH_ESCAPES) === 0) {
                $escaped = $token[++$position];
                $result .= match ($escaped) {
                    '0' => "\0",
                    'b' => "\x08",
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    'Z' => "\x1a",
                    // Outside pattern matching, MySQL retains these backslashes.
                    '%', '_' => '\\' . $escaped,
                    default => $escaped,
                };
            } elseif ($token[$position] === $quote && ($token[$position + 1] ?? null) === $quote) {
                $result .= $quote;
                ++$position;
            } else {
                $result .= $token[$position];
            }
        }

        return $result;
    }
}
