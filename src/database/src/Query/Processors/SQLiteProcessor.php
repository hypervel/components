<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Processors;

use Hypervel\Support\Arr;
use Override;
use UnexpectedValueException;

class SQLiteProcessor extends Processor
{
    #[Override]
    public function processColumns(array $results, string $sql = ''): array
    {
        $hasPrimaryKey = array_sum(array_column($results, 'primary')) === 1;

        return array_map(function ($result) use ($hasPrimaryKey, $sql) {
            $result = (object) $result;

            $type = strtolower($result->type);

            $safeName = preg_quote($result->name, '/');

            $collation = preg_match(
                '/\b' . $safeName . '\b[^,(]+(?:\([^()]+\)[^,]*)?(?:(?:default|check|as)\s*(?:\(.*?\))?[^,]*)*collate\s+["\'`]?(\w+)/i',
                $sql,
                $matches
            ) === 1 ? strtolower($matches[1]) : null;

            $isGenerated = in_array($result->extra, [2, 3]);

            $expression = $isGenerated && preg_match(
                '/\b' . $safeName . '\b[^,]+\s+as\s+\(((?:[^()]+|\((?:[^()]+|\([^()]*\))*\))*)\)/i',
                $sql,
                $matches
            ) === 1 ? $matches[1] : null;

            return [
                'name' => $result->name,
                'type_name' => strtok($type, '(') ?: '',
                'type' => $type,
                'collation' => $collation,
                'nullable' => (bool) $result->nullable,
                'default' => $result->default,
                'auto_increment' => $hasPrimaryKey && $result->primary && $type === 'integer',
                'comment' => null,
                'generation' => $isGenerated ? [
                    'type' => match ((int) $result->extra) {
                        3 => 'stored',
                        2 => 'virtual',
                        default => null,
                    },
                    'expression' => $expression,
                ] : null,
            ];
        }, $results);
    }

    #[Override]
    public function processIndexes(array $results): array
    {
        return array_map(
            static fn (array $index): array => Arr::only(
                $index,
                ['name', 'columns', 'type', 'unique', 'primary'],
            ),
            $this->processIndexesForSchemaState($results),
        );
    }

    /**
     * Process indexes with the metadata required to reconstruct SQLite schema state.
     *
     * @internal
     * @return list<array{name: string, physical_name: string, columns: list<string>, type: null|string, unique: bool, primary: bool, sql: null|string, origin: null|string, reconstructible: bool, collations: null|list<string>, descending: null|list<bool>}>
     */
    public function processIndexesForSchemaState(array $results): array
    {
        $primaryCount = 0;

        $indexes = array_map(function ($result) use (&$primaryCount) {
            $result = (object) $result;

            if ($isPrimary = (bool) $result->primary) {
                ++$primaryCount;
            }

            return [
                'name' => strtolower($result->name),
                'physical_name' => (string) $result->name,
                'columns' => $this->decodeHexList($result->columns),
                'type' => null,
                'unique' => (bool) $result->unique,
                'primary' => $isPrimary,
                'sql' => $result->sql,
                'origin' => $result->origin,
                'reconstructible' => (bool) $result->reconstructible,
                'collations' => is_null($result->collations)
                    ? null
                    : $this->decodeHexList($result->collations),
                'descending' => is_null($result->descending)
                    ? null
                    : array_map(
                        static fn (string $value): bool => $value === '1',
                        explode(',', $result->descending),
                    ),
            ];
        }, $results);

        if ($primaryCount > 1) {
            $indexes = array_filter($indexes, fn ($index) => $index['name'] !== 'primary');
        }

        return array_values($indexes);
    }

    /**
     * Decode a comma-separated list of hexadecimal SQLite schema values.
     *
     * @return list<string>
     */
    protected function decodeHexList(?string $values): array
    {
        if (is_null($values) || $values === '') {
            return [];
        }

        return array_map(static function (string $value): string {
            if (strlen($value) % 2 !== 0 || ! ctype_xdigit($value)) {
                throw new UnexpectedValueException('The SQLite schema metadata contains invalid hexadecimal text.');
            }

            /** @var string $decoded */
            $decoded = hex2bin($value);

            return $decoded;
        }, explode(',', $values));
    }

    #[Override]
    public function processForeignKeys(array $results): array
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => null,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => strtolower($result->on_update),
                'on_delete' => strtolower($result->on_delete),
            ];
        }, $results);
    }
}
