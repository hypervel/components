<?php

declare(strict_types=1);

namespace Hypervel\Wayfinder;

use Hypervel\Database\Eloquent\Model;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use Throwable;

class BindingResolver
{
    protected static array $booted = [];

    protected static array $columns = [];

    protected static array $docBlocks = [];

    protected static ?PhpDocParser $docParser = null;

    protected static ?Lexer $lexer = null;

    /**
     * Resolve the primitive types and binding key for a routable class.
     *
     * @return array{0: string[], 1: ?string}
     */
    public static function resolveTypesAndKey(string $routable, ?string $key): array
    {
        $booted = self::$booted[$routable] ??= app($routable);

        $key ??= $booted->getRouteKeyName();

        if (! $booted instanceof Model) {
            return [[], $key];
        }

        $types = self::primitiveCastTypes($booted->getCasts()[$key] ?? null)
            ?: self::schemaTypes($booted, $key)
            ?: self::phpDocTypes($booted, $key);

        return [$types, $key];
    }

    /**
     * Resolve primitive types from an Eloquent cast.
     *
     * @return string[]
     */
    protected static function primitiveCastTypes(?string $cast): array
    {
        if ($cast === null) {
            return [];
        }

        return self::primitiveTypes([strtolower(explode(':', $cast, 2)[0])]);
    }

    /**
     * Resolve primitive types from the model schema.
     *
     * @return string[]
     */
    protected static function schemaTypes(Model $model, string $key): array
    {
        self::$columns[$model::class] ??= self::getColumns($model);

        $column = collect(self::$columns[$model::class])->first(
            fn (array $column): bool => $column['name'] === $key,
        );

        return is_array($column)
            ? self::primitiveTypes([$column['type_name']])
            : [];
    }

    /**
     * Return column metadata for the model.
     */
    protected static function getColumns(Model $model): array
    {
        try {
            return $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Resolve primitive types from the model's class-level docblock.
     *
     * @return string[]
     */
    protected static function phpDocTypes(Model $model, string $key): array
    {
        self::$docBlocks[$model::class] ??= self::parseDocBlock($model);

        return self::$docBlocks[$model::class][$key] ?? [];
    }

    /**
     * Extract primitive property types from the model's class-level docblock.
     *
     * @return array<string, string[]>
     */
    protected static function parseDocBlock(Model $model): array
    {
        $doc = (new ReflectionClass($model))->getDocComment();

        if (! $doc) {
            return [];
        }

        self::$docParser ??= self::initDocParser();
        self::$lexer ??= self::initLexer();

        $tokens = new TokenIterator(self::$lexer->tokenize($doc));
        $phpDocNode = self::$docParser->parse($tokens);

        $tags = array_merge($phpDocNode->getPropertyTagValues(), $phpDocNode->getPropertyReadTagValues(), $phpDocNode->getPropertyWriteTagValues());

        $properties = [];

        foreach ($tags as $tag) {
            $type = $tag->type;

            $typeNames = match (true) {
                $type instanceof IdentifierTypeNode => [$type->name],
                $type instanceof UnionTypeNode => collect($type->types)
                    ->whereInstanceOf(IdentifierTypeNode::class)
                    ->map(fn (IdentifierTypeNode $type): string => $type->name)
                    ->all(),
                default => [],
            };
            $name = ltrim($tag->propertyName, '$');
            $properties[$name] = array_values(array_unique([
                ...($properties[$name] ?? []),
                ...self::primitiveTypes($typeNames),
            ]));
        }

        return $properties;
    }

    /**
     * Map raw primitive evidence to TypeScript primitives.
     *
     * @param string[] $types
     * @return string[]
     */
    protected static function primitiveTypes(array $types): array
    {
        return collect($types)
            ->map(fn (string $type): ?string => match (strtolower($type)) {
                'int', 'integer', 'bigint', 'int4', 'int8', 'serial', 'bigserial',
                'number', 'float', 'double', 'real' => 'number',
                'decimal', 'string', 'text', 'varchar', 'char', 'json', 'jsonb' => 'string',
                'bool', 'boolean' => 'boolean',
                default => null,
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a configured PhpDoc parser instance.
     */
    protected static function initDocParser(): PhpDocParser
    {
        $config = self::getParserConfig();
        $constExprParser = new ConstExprParser($config);
        $typeParser = new TypeParser($config, $constExprParser);

        return new PhpDocParser($config, $typeParser, $constExprParser);
    }

    /**
     * Build a configured PhpDoc lexer instance.
     */
    protected static function initLexer(): Lexer
    {
        return new Lexer(self::getParserConfig());
    }

    /**
     * Return the shared PhpDoc parser configuration.
     */
    protected static function getParserConfig(): ParserConfig
    {
        return new ParserConfig(usedAttributes: []);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$booted = [];
        self::$columns = [];
        self::$docBlocks = [];
        self::$docParser = null;
        self::$lexer = null;
    }
}
