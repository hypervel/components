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

    protected static ?PhpDocParser $docParser = null;

    protected static ?Lexer $lexer = null;

    /**
     * Resolve the type and binding key for a routable class.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolveTypeAndKey(string $routable, ?string $key): array
    {
        $booted = self::$booted[$routable] ??= app($routable);

        $key ??= $booted->getRouteKeyName();

        if (! $booted instanceof Model) {
            return [null, $key];
        }

        self::$columns[$routable] ??= self::getColumns($booted);

        return [
            collect(self::$columns[$routable])->first(
                fn (array $column) => $column['name'] === $key,
            )['type_name'] ?? null,
            $key,
        ];
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$booted = [];
        self::$columns = [];
        self::$docParser = null;
        self::$lexer = null;
    }

    /**
     * Return column metadata for the model, falling back to docblock parsing.
     */
    protected static function getColumns(Model $model): array
    {
        try {
            return $model->getConnection()->getSchemaBuilder()->getColumns($model->getTable());
        } catch (Throwable) {
            return self::parseDocBlock($model);
        }
    }

    /**
     * Extract column metadata from the model's class-level docblock.
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

        return collect($tags)->map(function ($tag) {
            $type = $tag->type;

            $typeName = match (true) {
                $type instanceof IdentifierTypeNode => $type->name,
                $type instanceof UnionTypeNode => collect($type->types)->whereInstanceOf(IdentifierTypeNode::class)->filter(fn (IdentifierTypeNode $t) => $t->name !== 'null')->map(fn (IdentifierTypeNode $t) => $t->name)->join('|'),
                default => 'mixed',
            };

            return [
                'name' => ltrim($tag->propertyName, '$'),
                'type_name' => $typeName,
            ];
        })->filter()->values()->all();
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
}
