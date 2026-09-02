<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Annotations;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

class DataIterableAnnotationReader
{
    protected readonly Lexer $lexer;

    protected readonly PhpDocParser $parser;

    /**
     * Create a new iterable annotation reader.
     */
    public function __construct()
    {
        $config = new ParserConfig(usedAttributes: []);
        $constantExpressionParser = new ConstExprParser($config);

        $this->lexer = new Lexer($config);
        $this->parser = new PhpDocParser(
            $config,
            new TypeParser($config, $constantExpressionParser),
            $constantExpressionParser,
        );
    }

    /**
     * Get iterable annotations declared for class properties.
     *
     * @param ReflectionClass<object> $class
     * @return array<string, non-empty-list<DataIterableAnnotation>>
     */
    public function getForClass(ReflectionClass $class): array
    {
        $node = $this->parse($class->getDocComment());

        if ($node === null) {
            return [];
        }

        $annotations = [];

        foreach ($node->getPropertyTagValues() as $tag) {
            $property = ltrim($tag->propertyName, '$');
            $resolved = $this->extract($tag->type, $class->getName(), $property);

            if ($resolved !== []) {
                $annotations[$property] = $resolved;
            }
        }

        return $annotations;
    }

    /**
     * Get iterable annotations declared for a property.
     *
     * @return list<DataIterableAnnotation>
     */
    public function getForProperty(ReflectionProperty $property): array
    {
        $node = $this->parse($property->getDocComment());
        $tag = $node?->getVarTagValues()[0] ?? null;

        return $tag === null
            ? []
            : $this->extract($tag->type, $property->getDeclaringClass()->getName());
    }

    /**
     * Get iterable annotations declared for method parameters.
     *
     * @return array<string, non-empty-list<DataIterableAnnotation>>
     */
    public function getForMethod(ReflectionMethod $method): array
    {
        $node = $this->parse($method->getDocComment());

        if ($node === null) {
            return [];
        }

        $annotations = [];

        foreach ($node->getParamTagValues() as $tag) {
            $parameter = ltrim($tag->parameterName, '$');
            $resolved = $this->extract(
                $tag->type,
                $method->getDeclaringClass()->getName(),
                $parameter,
            );

            if ($resolved !== []) {
                $annotations[$parameter] = $resolved;
            }
        }

        return $annotations;
    }

    /**
     * Parse a PHPDoc comment.
     */
    protected function parse(string|false $comment): ?PhpDocNode
    {
        if ($comment === false) {
            return null;
        }

        return $this->parser->parse(new TokenIterator($this->lexer->tokenize($comment)));
    }

    /**
     * Extract every iterable declaration from a type node.
     *
     * @param class-string $declaringClass
     * @return list<DataIterableAnnotation>
     */
    protected function extract(
        TypeNode $type,
        string $declaringClass,
        ?string $property = null,
    ): array {
        if ($type instanceof NullableTypeNode) {
            return $this->extract($type->type, $declaringClass, $property);
        }

        if ($type instanceof UnionTypeNode) {
            $annotations = [];

            foreach ($type->types as $subType) {
                array_push($annotations, ...$this->extract($subType, $declaringClass, $property));
            }

            return $annotations;
        }

        if ($type instanceof ArrayTypeNode) {
            return [new DataIterableAnnotation(
                containerType: 'array',
                itemType: $type->type,
                declaringClass: $declaringClass,
                keyType: new IdentifierTypeNode('array-key'),
                property: $property,
            )];
        }

        if (! $type instanceof GenericTypeNode) {
            return [];
        }

        $container = $type->type->name;
        $genericTypes = $type->genericTypes;

        if ($genericTypes === []) {
            return [];
        }

        if ($container === 'list' || $container === 'non-empty-list') {
            return [new DataIterableAnnotation(
                containerType: 'array',
                itemType: $genericTypes[0],
                declaringClass: $declaringClass,
                keyType: new IdentifierTypeNode('int'),
                property: $property,
            )];
        }

        return [new DataIterableAnnotation(
            containerType: $container,
            itemType: $genericTypes[1] ?? $genericTypes[0],
            declaringClass: $declaringClass,
            keyType: isset($genericTypes[1])
                ? $genericTypes[0]
                : new IdentifierTypeNode('array-key'),
            property: $property,
        )];
    }
}
