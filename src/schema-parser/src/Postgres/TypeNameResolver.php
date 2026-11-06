<?php

declare(strict_types=1);

namespace Hypervel\SchemaParser\Postgres;

use Hypervel\SchemaParser\ColumnType;
use sad_spirit\pg_builder\exceptions\SyntaxException;
use sad_spirit\pg_builder\Lexer;
use sad_spirit\pg_builder\Parser;

use function count;
use function trim;

/**
 * Turn the type text of a column into a ColumnType.
 *
 * The type grammar is delegated to pg_builder, which reimplements PostgreSQL's
 * own productions, so the multi-word standard spellings - `timestamp(0) without
 * time zone`, `double precision`, `character varying`, `interval day to
 * second(3)` - are understood and canonicalised rather than pattern-matched.
 *
 * @internal
 */
class TypeNameResolver
{
    /**
     * A dump repeats the same handful of type spellings across every table -
     * `integer` alone runs into the thousands - so resolving each one once turns
     * the type grammar from the dominant cost into a negligible one.
     *
     * @var array<string, ColumnType>
     */
    private array $cache = [];

    /**
     * Create a type resolver for one schema parse.
     */
    public function __construct(
        private readonly Parser $parser = new Parser(new Lexer),
    ) {
    }

    /**
     * Resolve type text, falling back to an opaque reference.
     *
     * A type this cannot read is still a column the consumer should know about,
     * so an unreadable type yields a ColumnType naming it verbatim rather than an
     * exception that would discard the rest of the table.
     */
    public function resolve(string $type): ColumnType
    {
        $type = trim($type);

        return $this->cache[$type] ??= $this->parse($type);
    }

    /**
     * Read the declared name, modifiers and array dimensions.
     */
    private function parse(string $type): ColumnType
    {
        try {
            $node = $this->parser->parseTypeName($type);
        } catch (SyntaxException) {
            // Unsupported grammar stays opaque without changing quoted names or hiding programming errors.
            return new ColumnType($type);
        }

        $modifiers = [];

        foreach ($node->modifiers as $modifier) {
            $modifiers[] = $modifier->value;
        }

        $schema = $node->name->schema;

        return new ColumnType(
            $node->name->relation->value,
            $schema?->value,
            $modifiers,
            count($node->bounds),
        );
    }
}
