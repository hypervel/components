<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Database\Eloquent\CastsAttributes;
use Hypervel\Contracts\Database\Eloquent\CastsInboundAttributes;
use Hypervel\Database\Eloquent\Casts\ArrayObject;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable as HypervelStringable;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Accessory\AccessoryNumericStringType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use ReflectionException;
use ReflectionProperty;
use stdClass;
use Stringable;

use function array_fill_keys;
use function array_filter;
use function array_find;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_merge;
use function array_shift;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_match;
use function str_contains;
use function strtolower;
use function trim;

#[AutowiredService]
class ModelCastHelper
{
    /** @var array<string, array{casts: array<string, null|string>, complete: bool}> */
    private array $modelCasts = [];

    /**
     * The primitive cast types Eloquent reads, which are never class casts.
     *
     * @var null|list<string>
     */
    private ?array $primitiveCastTypes = null;

    /**
     * Create a new model cast helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        // Unlike upstream, the cached parser analysis uses, which keeps method bodies for every file.
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
        #[AutowiredParameter]
        private bool $parseModelCastsMethod,
        private ScopeFactory $scopeFactory,
        private DeclarationDependencyTracker $declarationDependencyTracker,
        private ModelHelper $modelHelper,
    ) {
    }

    /**
     * Get the type a cast attribute reads as.
     */
    public function getReadableType(string $cast, Type $originalType): Type
    {
        $arguments = $this->parseCastArguments($cast);
        $cast = $this->parseCast($cast);

        $attributeType = match ($cast) {
            'int', 'integer', 'timestamp' => $originalType->isInteger()->yes() ? $originalType : new IntegerType,
            'real', 'float', 'double' => new FloatType,
            'decimal' => TypeCombinator::intersect(new StringType, new AccessoryNumericStringType),
            'string' => new StringType,
            'bool', 'boolean' => new BooleanType,
            'object' => new ObjectType(stdClass::class),
            'array', 'json' => new ArrayType(new BenevolentUnionType([new IntegerType, new StringType]), new MixedType),
            'collection' => new ObjectType(Collection::class),
            'date', 'datetime' => $this->getDateType(),
            'immutable_date', 'immutable_datetime' => new ObjectType(CarbonImmutable::class),
            // The caster reads a missing or non-array value as null.
            AsArrayObject::class, AsEncryptedArrayObject::class => new BenevolentUnionType([
                new ObjectType(ArrayObject::class),
                new NullType,
            ]),
            AsCollection::class, AsEncryptedCollection::class => new BenevolentUnionType([
                $this->getCollectionCastType($arguments),
                new NullType,
            ]),
            AsStringable::class => new ObjectType(HypervelStringable::class),
            default => null,
        };

        if ($attributeType) {
            return $attributeType;
        }

        if (! $this->reflectionProvider->hasClass($cast)) {
            return $originalType;
        }

        $classReflection = $this->reflectionProvider->getClass($cast);

        if ($classReflection->isEnum()) {
            return new ObjectType($cast);
        }

        $caster = $this->resolveCaster($classReflection, $arguments);

        if ($caster?->is(CastsAttributes::class)) {
            return $caster->getNativeMethod('get')->getVariants()[0]->getReturnType();
        }

        if ($caster?->is(CastsInboundAttributes::class)) {
            return $originalType;
        }

        return new MixedType;
    }

    /**
     * Get the type a cast attribute accepts.
     */
    public function getWriteableType(string $cast, Type $originalType): Type
    {
        $arguments = $this->parseCastArguments($cast);
        $cast = $this->parseCast($cast);

        $attributeType = match ($cast) {
            'int', 'integer', 'timestamp' => $originalType->isInteger()->yes() ? $originalType : new IntegerType,
            'real', 'float', 'double' => new FloatType,
            'decimal' => TypeCombinator::union(TypeCombinator::intersect(new StringType, new AccessoryNumericStringType), new IntegerType, new FloatType),
            'string' => new StringType,
            'bool', 'boolean' => TypeCombinator::union(new BooleanType, new ConstantIntegerType(0), new ConstantIntegerType(1)),
            'object' => new ObjectType(stdClass::class),
            'array', 'json' => new ArrayType(new BenevolentUnionType([new IntegerType, new StringType]), new MixedType),
            'collection' => new ObjectType(Collection::class),
            'date', 'datetime', 'immutable_date', 'immutable_datetime' => $this->getDateWriteableType(),
            AsArrayObject::class, AsCollection::class,
            AsEncryptedArrayObject::class, AsEncryptedCollection::class => new MixedType,
            AsStringable::class => TypeCombinator::union(new StringType, new ObjectType(Stringable::class)),
            default => null,
        };

        if ($attributeType) {
            return $attributeType;
        }

        if (! $this->reflectionProvider->hasClass($cast)) {
            return $originalType;
        }

        $classReflection = $this->reflectionProvider->getClass($cast);

        if ($classReflection->isEnum()) {
            return $this->getEnumWriteableType($classReflection);
        }

        $caster = $this->resolveCaster($classReflection, $arguments);

        if (
            $caster !== null
            && ($caster->is(CastsAttributes::class) || $caster->is(CastsInboundAttributes::class))
        ) {
            $methodReflection = $caster->getNativeMethod('set');
            $parameters = $methodReflection->getVariants()[0]->getParameters();

            $valueParameter = Arr::first($parameters, static fn (ParameterReflection $parameterReflection) => $parameterReflection->getName() === 'value');

            if ($valueParameter) {
                return $valueParameter->getType();
            }
        }

        return new MixedType;
    }

    /**
     * Get the type an enum cast attribute accepts: a case, or a value HasAttributes::getEnumCaseFromValue() resolves to one.
     */
    private function getEnumWriteableType(ClassReflection $enum): Type
    {
        $enumType = new ObjectType($enum->getName());
        $backingType = $enum->getBackedEnumType();

        return match (true) {
            // A pure enum's case is looked up by name.
            $backingType === null => TypeCombinator::union($enumType, new StringType),
            // enum_from() reads a numeric string as an integer value.
            $backingType->isInteger()->yes() => TypeCombinator::union(
                $enumType,
                new IntegerType,
                TypeCombinator::intersect(new StringType, new AccessoryNumericStringType),
            ),
            // enum_from() reads an integer as its string value.
            default => TypeCombinator::union($enumType, new StringType, new IntegerType),
        };
    }

    /**
     * Get the class that casts the attribute: the cast class itself, or the one caster its castUsing() returns.
     *
     * castUsing() is never called. A return that may name several classes, or one that is not known, leaves the
     * caster undetermined.
     *
     * @param list<string> $arguments
     */
    private function resolveCaster(ClassReflection $classReflection, array $arguments): ?ClassReflection
    {
        if (! $classReflection->is(Castable::class)) {
            return $classReflection;
        }

        $casterType = ParametersAcceptorSelector::selectFromTypes(
            [ConstantTypeHelper::getTypeFromValue($arguments)],
            $classReflection->getNativeMethod('castUsing')->getVariants(),
            false,
        )->getReturnType()->getObjectTypeOrClassStringObjectType();
        $casters = $casterType->getObjectClassReflections();

        return count($casters) === 1 && (new ObjectType($casters[0]->getName()))->isSuperTypeOf($casterType)->yes()
            ? $casters[0]
            : null;
    }

    /**
     * Get the collection a collection cast reads as: the class using() selects, with its templates at their bounds,
     * or the base collection.
     *
     * Unlike upstream, the selected class is kept. With a mapper, map() and mapInto() can return another class.
     *
     * @param list<string> $arguments
     */
    private function getCollectionCastType(array $arguments): Type
    {
        $class = $arguments[0] ?? '';
        $collection = $class !== '' && ($arguments[1] ?? '') === '' && $this->reflectionProvider->hasClass($class)
            ? $this->reflectionProvider->getClass($class)
            : null;

        if ($collection === null || ! $collection->is(Collection::class) || $collection->getName() === Collection::class) {
            return new GenericObjectType(Collection::class, [new BenevolentUnionType([new IntegerType, new StringType]), new MixedType]);
        }

        $templateTypes = array_values($collection->getTemplateTypeMap()->resolveToBounds()->getTypes());

        return $templateTypes === [] ? new ObjectType($collection->getName()) : new GenericObjectType($collection->getName(), $templateTypes);
    }

    /**
     * Get the type a date attribute reads as.
     */
    public function getDateType(): Type
    {
        // Unlike upstream, the booted date factory's class is not used: applications can
        // configure it, so asDateTime() only guarantees the interface.
        return new ObjectType(CarbonInterface::class);
    }

    /**
     * Get the type a date attribute accepts, which fromDateTime() converts.
     */
    public function getDateWriteableType(): Type
    {
        return TypeCombinator::union(new ObjectType(DateTimeInterface::class), new StringType, new IntegerType, new FloatType);
    }

    /**
     * Get the cast type without its encryption prefix and arguments.
     */
    private function parseCast(string $cast): string
    {
        $lowercase = strtolower(trim($cast));

        // Hypervel: as getCastType() does, a cast other than a class or a date or decimal cast with arguments matches
        // in lowercase, so ENCRYPTED:ARRAY is encrypted:array. A date or decimal cast with arguments matches only as written.
        if (! $this->reflectionProvider->hasClass(explode(':', $cast)[0])
            && preg_match('/^(?:date|datetime|immutable_date|immutable_datetime|decimal):/', $lowercase) !== 1) {
            $cast = $lowercase;
        }

        foreach (explode(':', $cast) as $part) {
            // If the cast is prefixed with `encrypted:` we need to skip to the next
            if ($part === 'encrypted') {
                continue;
            }

            return $part;
        }

        return $cast;
    }

    /**
     * Get the arguments of a cast.
     *
     * @return list<string>
     */
    private function parseCastArguments(string $cast): array
    {
        if (! str_contains($cast, ':')) {
            return [];
        }

        return explode(',', explode(':', $cast, 2)[1]);
    }

    /**
     * Determine whether the model casts the property, including with a cast that cannot be determined.
     */
    public function hasCastForProperty(ClassReflection $modelClassReflection, string $propertyName): bool
    {
        $modelCasts = $this->getModelCasts($modelClassReflection);

        return array_key_exists($propertyName, $modelCasts['casts']) || ! $modelCasts['complete'];
    }

    /**
     * Determine whether the model's casts name the property, which makes it an attribute even without a column.
     */
    public function hasCastEntry(ClassReflection $modelClassReflection, string $propertyName): bool
    {
        return array_key_exists($propertyName, $this->getModelCasts($modelClassReflection)['casts']);
    }

    /**
     * Get the model's cast for the property, or null when it has none or its cast cannot be determined.
     */
    public function getCastForProperty(ClassReflection $modelClassReflection, string $propertyName): ?string
    {
        return $this->getModelCasts($modelClassReflection)['casts'][$propertyName] ?? null;
    }

    /**
     * Get the class a cast names, as HasAttributes::isClassCastable() reads it, or null for a primitive cast type.
     *
     * Class names are case-insensitive, so a primitive type such as datetime would otherwise name DateTime.
     */
    public function getCastClass(string $cast): ?ClassReflection
    {
        $castType = explode(':', $cast, 2)[0];

        $this->primitiveCastTypes ??= (new ReflectionProperty(Model::class, 'primitiveCastTypes'))->getValue();

        return ! in_array($castType, $this->primitiveCastTypes, true) && $this->reflectionProvider->hasClass($castType)
            ? $this->reflectionProvider->getClass($castType)
            : null;
    }

    /**
     * Get the caster whose get() and set() methods type a class cast, which run even for null values.
     *
     * Returns null for primitive, enum and built-in object casts, which are typed here directly, and for a
     * Castable whose caster cannot be determined.
     */
    public function getCaster(string $cast): ?ClassReflection
    {
        $castType = $this->parseCast($cast);

        if (
            in_array($castType, [AsArrayObject::class, AsCollection::class, AsEncryptedArrayObject::class, AsEncryptedCollection::class, AsStringable::class], true)
            || ! $this->reflectionProvider->hasClass($castType)
        ) {
            return null;
        }

        $classReflection = $this->reflectionProvider->getClass($castType);

        if ($classReflection->isEnum()) {
            return null;
        }

        $caster = $this->resolveCaster($classReflection, $this->parseCastArguments($cast));

        return $caster !== null && ($caster->is(CastsAttributes::class) || $caster->is(CastsInboundAttributes::class))
            ? $caster
            : null;
    }

    /**
     * Determine whether a cast reads an empty string as null, as the JSON casts do through HasAttributes::fromJson().
     */
    public function readsEmptyStringAsNull(string $cast): bool
    {
        return in_array(strtolower(trim($cast)), ['array', 'json', 'json:unicode', 'object'], true);
    }

    /**
     * Get the model's casts, including those its casts() method returns.
     *
     * A null cast is present but cannot be determined. Unless the casts are complete, any other
     * property may have such a cast too.
     *
     * @return array{casts: array<string, null|string>, complete: bool}
     *
     * @throws ShouldNotHappenException
     */
    private function getModelCasts(ClassReflection $modelClassReflection): array
    {
        $className = $modelClassReflection->getName();

        if (array_key_exists($className, $this->modelCasts)) {
            return $this->modelCasts[$className];
        }

        try {
            $modelInstance = $this->modelHelper->getInstance($modelClassReflection);
        } catch (ReflectionException) {
            throw new ShouldNotHappenException;
        }

        // The instance skips the constructor, so these are the $casts property's casts, without casts()
        // and without the normalization of [$cast, ...$arguments] that initializeHasAttributes() runs.
        $propertyCasts = array_map(
            fn (mixed $cast): ?string => $this->getCastFromType(ConstantTypeHelper::getTypeFromValue($cast)),
            $modelInstance->getCasts(),
        );
        $castsMethod = $modelClassReflection->getNativeMethod('casts');

        if ($castsMethod->getDeclaringClass()->getName() === Model::class) {
            return $this->modelCasts[$className] = ['casts' => $propertyCasts, 'complete' => true];
        }

        $declaredCasts = $this->getCastsFromType($castsMethod->getVariants()[0]->getReturnType());
        $returnedType = $this->parseModelCastsMethod ? $this->parseCastsMethod($modelClassReflection, $castsMethod) : null;
        $returnedCasts = $returnedType === null ? null : $this->getCastsFromType($returnedType);
        $methodCasts = $returnedCasts ?? $declaredCasts;

        if ($returnedCasts !== null && $declaredCasts !== null) {
            // A cast the method body builds at runtime may still be declared in its return type.
            foreach ($returnedCasts['casts'] as $attribute => $cast) {
                $methodCasts['casts'][$attribute] = $cast ?? $declaredCasts['casts'][$attribute] ?? null;
            }
        }

        // Unknown method casts can replace the property's casts, but its keys still name attributes.
        return $this->modelCasts[$className] = $methodCasts !== null && $methodCasts['closed']
            ? ['casts' => array_merge($propertyCasts, $methodCasts['casts']), 'complete' => true]
            : ['casts' => array_merge(array_fill_keys(array_keys($propertyCasts), null), $methodCasts['casts'] ?? []), 'complete' => false];
    }

    /**
     * Get the casts a casts() return type sets, with null for those that cannot be determined, and
     * whether it sets no others; or null when the type is not a single array shape.
     *
     * @return null|array{casts: array<string, null|string>, closed: bool}
     */
    private function getCastsFromType(Type $type): ?array
    {
        $arrays = $type->getConstantArrays();

        if (count($arrays) !== 1 || ! $type->isConstantArray()->yes()) {
            return null;
        }

        $casts = [];

        foreach ($arrays[0]->getKeyTypes() as $index => $keyType) {
            // An optional cast may leave the $casts property's cast, or none, in place.
            $casts[(string) $keyType->getValue()] = $arrays[0]->isOptionalKey($index)
                ? null
                : $this->getCastFromType($arrays[0]->getValueTypes()[$index]);
        }

        // Outside bleeding edge, PHPStan leaves the sealedness of shapes without "..." undecided,
        // though it treats their missing keys as absent.
        return ['casts' => $casts, 'closed' => ! $arrays[0]->isUnsealed()->yes()];
    }

    /**
     * Get the cast a casts() value defines, with array definitions normalized as Eloquent does, or null
     * when the value is not a single constant definition.
     */
    private function getCastFromType(Type $type): ?string
    {
        $strings = $type->getConstantStrings();

        if (count($strings) === 1) {
            return $strings[0]->getValue();
        }

        $arrays = $type->getConstantArrays();

        if (count($arrays) !== 1 || ! $type->isConstantArray()->yes() || ! $arrays[0]->isList()->yes()) {
            return null;
        }

        $definition = [];

        foreach ($arrays[0]->getValueTypes() as $index => $valueType) {
            $values = $valueType->getConstantScalarValues();

            if (count($values) !== 1 || $arrays[0]->isOptionalKey($index)) {
                return null;
            }

            $definition[] = $values[0];
        }

        // As HasAttributes::ensureCastsAreStringValues() normalizes [$cast, ...$arguments].
        $cast = array_shift($definition);

        if (! is_string($cast)) {
            return null;
        }

        return $definition === [] ? $cast : $cast . ':' . implode(',', $definition);
    }

    /**
     * Get the type of the array an overridden casts() method returns, or null when its body has no
     * single unconditional array return to read.
     */
    private function parseCastsMethod(ClassReflection $modelClassReflection, ExtendedMethodReflection $castsMethod): ?Type
    {
        // The class using a trait declares its methods, which is also what self:: refers to in them.
        $declaringClass = $castsMethod->getDeclaringClass();
        // The native method carries the file and line of a trait's method.
        $nativeMethod = $declaringClass->getNativeReflection()->getMethod('casts');
        $fileName = (string) $nativeMethod->getFileName();
        $nodeFinder = new NodeFinder;

        /** @var ClassMethod $methodNode */
        $methodNode = $nodeFinder->findFirst(
            $this->parser->parseFile($fileName),
            static fn (Node $node): bool => $node instanceof ClassMethod
                && $node->name->toLowerString() === 'casts'
                && $node->getStartLine() === $nativeMethod->getStartLine(),
        );
        $statements = $methodNode->stmts ?? [];

        // Returns inside nested closures and classes belong to them.
        $nestedReturns = $nodeFinder->findInstanceOf(
            $nodeFinder->find($statements, static fn (Node $node): bool => $node instanceof FunctionLike || $node instanceof ClassLike),
            Return_::class,
        );
        $returns = array_filter(
            $nodeFinder->findInstanceOf($statements, Return_::class),
            static fn (Return_ $return): bool => ! in_array($return, $nestedReturns, true),
        );
        $return = array_find($statements, static fn (Node $statement): bool => $statement instanceof Return_);

        if (count($returns) !== 1 || ! $return instanceof Return_ || ! $return->expr instanceof Array_) {
            return null;
        }

        $scope = $this->scopeFactory->create(ScopeContext::create($fileName))->enterClass($declaringClass);

        $this->trackReferencedSources($modelClassReflection, $return->expr, $scope);

        return $scope->getType($return->expr);
    }

    /**
     * Track the files declaring the constants and static methods the returned casts use.
     *
     * Dependents of the model are otherwise analyzed again only when the model's own declarations change.
     */
    private function trackReferencedSources(ClassReflection $modelClassReflection, Array_ $casts, Scope $scope): void
    {
        $references = (new NodeFinder)->find(
            $casts,
            static fn (Node $node): bool => ($node instanceof ClassConstFetch || $node instanceof StaticCall)
                && $node->class instanceof Name
                && $node->name instanceof Identifier,
        );

        foreach ($references as $reference) {
            /** @var ClassConstFetch|StaticCall $reference */
            $className = $scope->resolveName($reference->class);
            $name = $reference->name->toString();

            if (! $this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $class = $this->reflectionProvider->getClass($className);

            // A class below the declaring one can override the member.
            $files = match (true) {
                $reference instanceof StaticCall => $class->hasNativeMethod($name)
                    ? SourceFiles::forClass($class, $class->getNativeMethod($name)->getDeclaringClass()->getParentClass()?->getName())
                    : [],
                $name === 'class' => [],
                default => $class->hasConstant($name) ? SourceFiles::forConstant($this->reflectionProvider, $class, $name) : [],
            };

            foreach ($files as $file) {
                $this->declarationDependencyTracker->trackFileDependency($modelClassReflection, $file);
            }
        }
    }
}
