<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Factories;

use Hypervel\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Hypervel\Contracts\Pagination\Paginator as PaginatorContract;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\DataTypeKind;
use Hypervel\Data\Exceptions\CannotFindDataClass;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Annotations\DataIterableAnnotation;
use Hypervel\Data\Support\DataAttributesCollection;
use Hypervel\Data\Support\DataPropertyType;
use Hypervel\Data\Support\DataType;
use Hypervel\Data\Support\Types\IntersectionType;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Support\Types\Type;
use Hypervel\Data\Support\Types\UnionType;
use Hypervel\Pagination\AbstractCursorPaginator;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Support\ClassMetadataCache;
use Hypervel\Support\Enumerable;
use InvalidArgumentException;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode as PhpDocIntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode as PhpDocUnionTypeNode;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use Traversable;

class DataTypeFactory
{
    /**
     * Create a new data type factory.
     */
    public function __construct(
        protected readonly PhpDocTypeNameResolver $typeNameResolver,
    ) {
    }

    /**
     * Build a data property type.
     *
     * @param class-string|ReflectionClass<object> $class
     * @param list<DataIterableAnnotation> $iterableAnnotations
     */
    public function buildProperty(
        ?ReflectionType $reflectionType,
        ReflectionClass|string $class,
        ReflectionProperty|ReflectionParameter|string $typeable,
        ?DataAttributesCollection $attributes = null,
        array $iterableAnnotations = [],
    ): DataPropertyType {
        $class = $this->reflectionClass($class);
        $declaringClass = $this->declaringClass($typeable, $class);

        $collectionOf = $attributes?->first(DataCollectionOf::class)?->newInstance();
        $type = $this->buildNativeType(
            $reflectionType,
            $class,
            $declaringClass,
            $typeable,
            $iterableAnnotations,
            $collectionOf instanceof DataCollectionOf ? $collectionOf->class : null,
            true,
        );
        $namedTypes = $type->getNamedTypes();

        return new DataPropertyType(
            type: $type,
            isOptional: $this->containsType($namedTypes, Optional::class),
            isNullable: $reflectionType?->allowsNull() ?? true,
            isMixed: $this->containsType($namedTypes, 'mixed'),
            lazyType: $this->findLazyType($namedTypes),
        );
    }

    /**
     * Build a parameter or return data type.
     *
     * @param class-string|ReflectionClass<object> $class
     */
    public function build(
        ?ReflectionType $reflectionType,
        ReflectionClass|string $class,
        ReflectionMethod|ReflectionProperty|ReflectionParameter|string $typeable,
    ): DataType {
        $class = $this->reflectionClass($class);
        $type = $this->buildNativeType(
            $reflectionType,
            $class,
            $this->declaringClass($typeable, $class),
            $typeable,
        );

        return new DataType(
            type: $type,
            isNullable: $reflectionType?->allowsNull() ?? true,
            isMixed: $this->containsType($type->getNamedTypes(), 'mixed'),
        );
    }

    /**
     * Build a data type from a declared type name.
     *
     * @param class-string|ReflectionClass<object> $class
     */
    public function buildFromString(
        string $type,
        ReflectionClass|string $class,
        bool $isBuiltIn,
        bool $isNullable = false,
    ): DataType {
        $class = $this->reflectionClass($class);
        $namedType = $this->buildNamedType(
            $this->resolveNativeName($type, $class, $class),
            $isBuiltIn,
        );

        return new DataType(
            type: $namedType,
            isNullable: $isNullable,
            isMixed: $namedType->name === 'mixed',
        );
    }

    /**
     * Build a reflected type graph.
     *
     * @param ReflectionClass<object> $targetClass
     * @param ReflectionClass<object> $declaringClass
     * @param list<DataIterableAnnotation> $iterableAnnotations
     */
    protected function buildNativeType(
        ?ReflectionType $reflectionType,
        ReflectionClass $targetClass,
        ReflectionClass $declaringClass,
        ReflectionMethod|ReflectionProperty|ReflectionParameter|string $typeable,
        array $iterableAnnotations = [],
        ?string $collectionOf = null,
        bool $forProperty = false,
    ): Type {
        if ($reflectionType === null) {
            return $this->buildNamedType('mixed', true);
        }

        if ($reflectionType instanceof ReflectionNamedType) {
            $name = $this->resolveNativeName(
                $reflectionType->getName(),
                $targetClass,
                $declaringClass,
            );
            $itemType = null;

            $kind = $this->kindFor($name);

            if ($collectionOf !== null && ($kind->isNonDataIterable() || $kind->isDataCollectable())) {
                $itemType = $this->buildNamedType($collectionOf, false);
            } elseif ($annotation = $this->matchingAnnotation($name, $targetClass, $iterableAnnotations)) {
                $annotationClass = ClassMetadataCache::reflectClass($annotation->declaringClass);
                $itemType = $this->buildPhpDocType(
                    $annotation->itemType,
                    $targetClass,
                    $annotationClass,
                );
            }

            $type = $this->buildNamedType(
                $name,
                $reflectionType->isBuiltin(),
                $itemType,
            );

            if ($forProperty && $this->requiresDataItemType($type->kind) && $type->dataClass === null) {
                throw CannotFindDataClass::forTypeable($typeable);
            }

            return $type;
        }

        if ($reflectionType instanceof ReflectionUnionType || $reflectionType instanceof ReflectionIntersectionType) {
            $types = [];

            foreach ($reflectionType->getTypes() as $subType) {
                $types[] = $this->buildNativeType(
                    $subType,
                    $targetClass,
                    $declaringClass,
                    $typeable,
                    $iterableAnnotations,
                    $collectionOf,
                    $forProperty,
                );
            }

            return $reflectionType instanceof ReflectionUnionType
                ? new UnionType($types)
                : new IntersectionType($types);
        }

        throw new InvalidArgumentException('Unsupported reflected data type.');
    }

    /**
     * Build a PHPDoc type graph.
     *
     * @param ReflectionClass<object> $targetClass
     * @param ReflectionClass<object> $declaringClass
     */
    protected function buildPhpDocType(
        TypeNode $type,
        ReflectionClass $targetClass,
        ReflectionClass $declaringClass,
    ): Type {
        if ($type instanceof IdentifierTypeNode) {
            if ($type->name === 'array-key') {
                return $this->buildArrayKeyType();
            }

            $name = $this->normalizePhpDocType($type->name);

            return $this->buildNamedType(
                $this->resolvePhpDocName($name, $targetClass, $declaringClass),
                $this->isBuiltIn($name),
            );
        }

        if ($type instanceof ThisTypeNode) {
            return $this->buildNamedType($targetClass->getName(), false);
        }

        if ($type instanceof NullableTypeNode) {
            return new UnionType([
                $this->buildPhpDocType($type->type, $targetClass, $declaringClass),
                $this->buildNamedType('null', true),
            ]);
        }

        if ($type instanceof PhpDocUnionTypeNode || $type instanceof PhpDocIntersectionTypeNode) {
            $types = array_map(
                fn (TypeNode $subType): Type => $this->buildPhpDocType(
                    $subType,
                    $targetClass,
                    $declaringClass,
                ),
                $type->types,
            );

            return $type instanceof PhpDocUnionTypeNode
                ? new UnionType($types)
                : new IntersectionType($types);
        }

        if ($type instanceof ArrayTypeNode) {
            return $this->buildNamedType(
                'array',
                true,
                $this->buildPhpDocType($type->type, $targetClass, $declaringClass),
            );
        }

        if ($type instanceof GenericTypeNode) {
            $name = $type->type->name;
            $genericTypes = $type->genericTypes;

            if ($name === 'list' || $name === 'non-empty-list') {
                return $this->buildNamedType(
                    'array',
                    true,
                    $this->buildPhpDocType($genericTypes[0], $targetClass, $declaringClass),
                );
            }

            $resolved = $this->resolvePhpDocName(
                $this->normalizePhpDocType($name),
                $targetClass,
                $declaringClass,
            );
            $itemType = null;

            if (isset($genericTypes[1])) {
                $itemType = $this->buildPhpDocType($genericTypes[1], $targetClass, $declaringClass);
            } elseif (isset($genericTypes[0])) {
                $itemType = $this->buildPhpDocType($genericTypes[0], $targetClass, $declaringClass);
            }

            return $this->buildNamedType(
                $resolved,
                $this->isBuiltIn($resolved),
                $itemType,
            );
        }

        if ($type instanceof ConstTypeNode) {
            $name = (string) $type;

            if (in_array($name, ['true', 'false', 'null'], true)) {
                return $this->buildNamedType($name, true);
            }
        }

        return $this->buildNamedType('mixed', true);
    }

    /**
     * Build one named type and its iterable metadata.
     */
    protected function buildNamedType(
        string $name,
        bool $builtIn,
        ?Type $itemType = null,
    ): NamedType {
        $kind = $this->kindFor($name);
        $dataClass = $kind->isDataObject() ? $name : $this->uniqueDataClass($itemType);

        if ($itemType !== null && $dataClass !== null && $kind->isNonDataIterable()) {
            $kind = $kind->getDataRelatedEquivalent();
        }

        return new NamedType(
            name: $name,
            builtIn: $builtIn,
            kind: $kind,
            dataClass: $dataClass,
            iterableClass: $kind->isNonDataIterable() || $kind->isDataCollectable() ? $name : null,
            iterableItemType: $itemType,
        );
    }

    /**
     * Find the iterable annotation matching a native container type.
     *
     * @param ReflectionClass<object> $targetClass
     * @param list<DataIterableAnnotation> $annotations
     */
    protected function matchingAnnotation(
        string $nativeType,
        ReflectionClass $targetClass,
        array $annotations,
    ): ?DataIterableAnnotation {
        $fallback = null;

        foreach ($annotations as $annotation) {
            $container = $this->resolvePhpDocName(
                $this->normalizePhpDocType($annotation->containerType),
                $targetClass,
                ClassMetadataCache::reflectClass($annotation->declaringClass),
            );

            if ($container === $nativeType) {
                return $annotation;
            }

            if ($fallback === null
                && (
                    ($container === 'iterable' && $this->kindFor($nativeType)->isNonDataIterable())
                    || (! $this->isBuiltIn($container) && is_a($nativeType, $container, true))
                )
            ) {
                $fallback = $annotation;
            }
        }

        return $fallback;
    }

    /**
     * Resolve a native self, static, or parent type name.
     *
     * @param ReflectionClass<object> $targetClass
     * @param ReflectionClass<object> $declaringClass
     */
    protected function resolveNativeName(
        string $name,
        ReflectionClass $targetClass,
        ReflectionClass $declaringClass,
    ): string {
        return match ($name) {
            'self' => $declaringClass->getName(),
            'static' => $targetClass->getName(),
            'parent' => $declaringClass->getParentClass()?->getName() ?? $name,
            default => $name,
        };
    }

    /**
     * Resolve a PHPDoc type name in its declaration and target scopes.
     *
     * @param ReflectionClass<object> $targetClass
     * @param ReflectionClass<object> $declaringClass
     */
    protected function resolvePhpDocName(
        string $name,
        ReflectionClass $targetClass,
        ReflectionClass $declaringClass,
    ): string {
        return match ($name) {
            'self' => $declaringClass->getName(),
            'static', '$this' => $targetClass->getName(),
            'parent' => $declaringClass->getParentClass()?->getName() ?? $name,
            default => $this->typeNameResolver->resolve($name, $declaringClass),
        };
    }

    /**
     * Resolve the semantic kind for a named type.
     */
    protected function kindFor(string $name): DataTypeKind
    {
        return match (true) {
            $name === DataCollection::class || is_a($name, DataCollection::class, true) => DataTypeKind::DataCollection,
            $name === PaginatedDataCollection::class || is_a($name, PaginatedDataCollection::class, true) => DataTypeKind::DataPaginatedCollection,
            $name === CursorPaginatedDataCollection::class || is_a($name, CursorPaginatedDataCollection::class, true) => DataTypeKind::DataCursorPaginatedCollection,
            is_a($name, BaseData::class, true) => DataTypeKind::DataObject,
            $name === 'array' => DataTypeKind::Array,
            $name === 'iterable' => DataTypeKind::Iterable,
            is_a($name, CursorPaginatorContract::class, true) || is_a($name, AbstractCursorPaginator::class, true) => DataTypeKind::CursorPaginator,
            is_a($name, PaginatorContract::class, true) || is_a($name, AbstractPaginator::class, true) => DataTypeKind::Paginator,
            is_a($name, Enumerable::class, true) => DataTypeKind::Enumerable,
            is_a($name, Traversable::class, true) => DataTypeKind::Iterable,
            default => DataTypeKind::Default,
        };
    }

    /**
     * Find the one data class declared by an iterable item type.
     *
     * @return null|class-string<BaseData>
     */
    protected function uniqueDataClass(?Type $type): ?string
    {
        if ($type === null) {
            return null;
        }

        $classes = [];

        foreach ($type->getNamedTypes() as $namedType) {
            if ($namedType->kind->isDataObject() && $namedType->dataClass !== null) {
                $classes[$namedType->dataClass] = true;
            }
        }

        return count($classes) === 1 ? array_key_first($classes) : null;
    }

    /**
     * Build the PHPDoc array-key union.
     */
    protected function buildArrayKeyType(): UnionType
    {
        return new UnionType([
            $this->buildNamedType('int', true),
            $this->buildNamedType('string', true),
        ]);
    }

    /**
     * Determine if a data collection kind requires one concrete data item type.
     */
    protected function requiresDataItemType(DataTypeKind $kind): bool
    {
        return $kind === DataTypeKind::DataCollection
            || $kind === DataTypeKind::DataPaginatedCollection
            || $kind === DataTypeKind::DataCursorPaginatedCollection;
    }

    /**
     * Determine if the named types contain an exact declaration.
     *
     * @param list<NamedType> $types
     */
    protected function containsType(array $types, string $name): bool
    {
        foreach ($types as $type) {
            if ($type->name === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the declared Lazy implementation.
     *
     * @param list<NamedType> $types
     * @return null|class-string<Lazy>
     */
    protected function findLazyType(array $types): ?string
    {
        foreach ($types as $type) {
            if ($type->name === Lazy::class || is_a($type->name, Lazy::class, true)) {
                return $type->name;
            }
        }

        return null;
    }

    /**
     * Normalize PHPDoc aliases to native type names.
     */
    protected function normalizePhpDocType(string $type): string
    {
        return match (strtolower($type)) {
            'boolean' => 'bool',
            'double', 'real' => 'float',
            'integer', 'negative-int', 'non-negative-int', 'non-positive-int', 'positive-int' => 'int',
            'callable-string', 'class-string', 'literal-string', 'non-empty-string', 'numeric-string' => 'string',
            default => $type,
        };
    }

    /**
     * Determine if a name is a built-in type.
     */
    protected function isBuiltIn(string $type): bool
    {
        return in_array($type, [
            'array',
            'bool',
            'callable',
            'false',
            'float',
            'int',
            'iterable',
            'mixed',
            'never',
            'null',
            'object',
            'resource',
            'string',
            'true',
            'void',
        ], true);
    }

    /**
     * Get the class that declared a reflected type.
     *
     * @param ReflectionClass<object> $targetClass
     * @return ReflectionClass<object>
     */
    protected function declaringClass(
        ReflectionMethod|ReflectionProperty|ReflectionParameter|string $typeable,
        ReflectionClass $targetClass,
    ): ReflectionClass {
        if ($typeable instanceof ReflectionParameter) {
            return $typeable->getDeclaringClass() ?? $targetClass;
        }

        if ($typeable instanceof ReflectionMethod || $typeable instanceof ReflectionProperty) {
            return $typeable->getDeclaringClass();
        }

        return $targetClass;
    }

    /**
     * Get the reflected class context.
     *
     * @param class-string|ReflectionClass<object> $class
     * @return ReflectionClass<object>
     */
    protected function reflectionClass(ReflectionClass|string $class): ReflectionClass
    {
        return is_string($class) ? ClassMetadataCache::reflectClass($class) : $class;
    }
}
