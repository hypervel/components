<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Grammars\Grammar;
use InvalidArgumentException;

class SearchableSchema
{
    /**
     * Register the "searchable" schema blueprint macros.
     */
    public static function register(): void
    {
        // Unlike upstream, there is no tsvector type macro or PostgreSQL guard: the schema grammars compile the
        // tsvector column natively, and other drivers reject it before the extension command is compiled.
        Grammar::macro('compileScoutTrigramExtension', fn (): string => 'create extension if not exists pg_trgm');

        Blueprint::macro('searchable', function (array $columns, string $language = 'english', array $trigram = [], string $tsvector = 'search_vector'): void {
            $this->addColumn('tsvector', $tsvector)->storedAs(
                SearchableSchema::vectorExpression($columns, $language)
            );

            $this->index($tsvector, SearchableSchema::indexName($this->createIndexName('index', [$tsvector])), 'gin');

            if ($trigram === []) {
                return;
            }

            $this->addCommand('scoutTrigramExtension');

            foreach ($trigram as $column) {
                SearchableSchema::ensureValidIdentifier($column);

                $this->rawIndex("\"{$column}\" gin_trgm_ops", SearchableSchema::indexName($this->createIndexName('trigram', [$column])))->algorithm('gin');
            }
        });

        Blueprint::macro('dropSearchable', function (array $trigram = [], string $tsvector = 'search_vector'): void {
            // Unlike upstream, the index name isn't qualified with the table's schema here: the PostgreSQL grammar
            // already qualifies dropped indexes, and a qualified name would get the table prefix on its schema.
            foreach ($trigram as $column) {
                $this->dropIndex(SearchableSchema::indexName($this->createIndexName('trigram', [$column])));
            }

            $this->dropColumn($tsvector);
        });
    }

    /**
     * Fit the given generated index name within PostgreSQL's 63-byte identifier limit.
     *
     * Unlike upstream, an overlong name is shortened deterministically rather than left for PostgreSQL to truncate,
     * since two generated names sharing their first 63 bytes would collide. The cut never splits a character.
     *
     * @internal
     */
    public static function indexName(string $name): string
    {
        if (strlen($name) <= 63) {
            return $name;
        }

        return mb_strcut($name, 0, 46, 'UTF-8') . '_' . substr(hash('xxh128', $name), 0, 16);
    }

    /**
     * Build the weighted tsvector expression for the given columns.
     *
     * @internal
     *
     * @param array<int|string, string> $columns column names, optionally keyed by column with an A-D weight value
     */
    public static function vectorExpression(array $columns, string $language): string
    {
        if ($columns === []) {
            throw new InvalidArgumentException('At least one searchable column is required.');
        }

        static::ensureValidLanguage($language);

        return collect($columns)->map(function (string $weight, int|string $column) use ($language): string {
            [$column, $weight] = is_int($column) ? [$weight, 'D'] : [$column, strtoupper($weight)];

            static::ensureValidIdentifier($column);

            if (! in_array($weight, ['A', 'B', 'C', 'D'], true)) {
                throw new InvalidArgumentException("Invalid full-text weight [{$weight}] for column [{$column}]. Use A, B, C, or D.");
            }

            return "setweight(to_tsvector('{$language}', coalesce(\"{$column}\"::text, '')), '{$weight}')";
        })->implode(' || ');
    }

    /**
     * Ensure the given text search config name is a plain, optionally schema-qualified, identifier.
     *
     * @internal
     */
    public static function ensureValidLanguage(string $language): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $language)) {
            throw new InvalidArgumentException("Invalid full-text search language [{$language}].");
        }
    }

    /**
     * Ensure the given column name is a plain identifier.
     *
     * @internal
     */
    public static function ensureValidIdentifier(string $column): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
            throw new InvalidArgumentException("Invalid searchable column [{$column}].");
        }
    }
}
