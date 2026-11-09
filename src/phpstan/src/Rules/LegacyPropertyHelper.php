<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\PHPStan\Reflection\SourceFiles;
use Hypervel\PHPStan\Support\ConstructorParser;
use PhpParser\Node\Expr;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\PrettyPrinter\Standard;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\InitializerExprContext;
use PHPStan\Reflection\InitializerExprTypeResolver;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;

use function array_filter;
use function array_is_list;
use function array_key_last;
use function count;
use function implode;
use function is_array;
use function is_scalar;
use function reset;
use function var_export;

/**
 * Reads configuration property declarations and the class attributes that can replace them,
 * for the rules recommending attributes over legacy model and queue properties.
 *
 * Values are PHP constants, with an enum case held as its PHPStan type.
 */
#[AutowiredService]
class LegacyPropertyHelper
{
    /**
     * Create a new legacy property helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private InitializerExprTypeResolver $initializerExprTypeResolver,
        private ConstructorParser $constructorParser,
    ) {
    }

    /**
     * Get the class's own fixed declarations of the given properties, and those of the traits it uses directly.
     *
     * A property with no default, one the constructor may change, or one whose default is not a single constant
     * holds per-instance state or cannot move to an attribute, so it is left out. A trait's declaration is included
     * when the class uses that trait directly, no other direct trait declares the property, and its default means
     * the same in the trait, since attribute lookup reads no nested traits and an attribute on the trait reads its
     * arguments there. Its line is in the trait's file.
     *
     * @template TProperty of string
     *
     * @param list<TProperty> $properties
     * @param array<string, list<string>> $setters the instance methods changing each property
     * @return array<TProperty, array{value: mixed, expression: ?Expr, line: int, trait: ?ClassReflection, public: bool}>
     */
    public function declarations(ClassReflection $classReflection, ClassLike $class, array $properties, array $setters): array
    {
        $declarations = [];

        foreach ($properties as $name) {
            if (! $classReflection->hasNativeProperty($name)) {
                continue;
            }

            $property = $classReflection->getNativeReflection()->getProperty($name);

            if (
                $property->getDeclaringClass()->getName() !== $classReflection->getName()
                || $property->isStatic()
                || ! $property->hasDefaultValue()
                || $this->constructorParser->writesProperty($classReflection, $name, $setters[$name] ?? [])
            ) {
                continue;
            }

            $item = $this->propertyItem($class, $name);
            $trait = $item === null ? $this->declaringTrait($classReflection, $name) : null;
            // An untyped property without a default has a null default but no expression.
            $expression = $trait === null ? $item?->default : $property->getBetterReflection()->getDefaultValueExpression();

            if ($expression === null) {
                continue;
            }

            $value = $this->constantValue($this->initializerExprTypeResolver->getType(
                $expression,
                InitializerExprContext::fromClass($classReflection->getName(), $trait?->getFileName() ?? $classReflection->getFileName()),
            ));

            if ($value === null) {
                continue;
            }

            // In the trait, `self` and `__CLASS__` name the trait rather than the class using it.
            if ($trait !== null) {
                $traitValue = $this->constantValue($this->initializerExprTypeResolver->getType($expression, InitializerExprContext::fromClassReflection($trait)));

                if ($traitValue === null || ! $this->same($traitValue[0], $value[0])) {
                    continue;
                }
            }

            $declarations[$name] = [
                'value' => $value[0],
                'expression' => $item?->default,
                'line' => $item?->getStartLine() ?? $property->getBetterReflection()->getStartLine(),
                'trait' => $trait,
                'public' => $property->isPublic(),
            ];
        }

        return $declarations;
    }

    /**
     * Get the default a property has without the class's own declaration: its parent's, with the class
     * declaring it there and whether it is public. Null when that default is not one constant.
     *
     * @return null|array{value: mixed, declarer: ?string, public: bool}
     */
    public function inheritedDefault(ClassReflection $classReflection, string $name): ?array
    {
        $parent = $classReflection->getParentClass();

        if ($parent === null || ! $parent->hasNativeProperty($name)) {
            return ['value' => null, 'declarer' => null, 'public' => false];
        }

        $property = $parent->getNativeReflection()->getProperty($name);

        if (! $property->hasDefaultValue()) {
            return null;
        }

        $declarer = $property->getDeclaringClass();
        $expression = $property->getBetterReflection()->getDefaultValueExpression();
        $value = $expression === null ? [null] : $this->constantValue($this->initializerExprTypeResolver->getType(
            $expression,
            InitializerExprContext::fromClass($declarer->getName(), $declarer->getFileName() ?: null),
        ));

        return $value === null ? null : ['value' => $value[0], 'declarer' => $declarer->getName(), 'public' => $property->isPublic()];
    }

    /**
     * Get the given attributes on the class and each parent below the base class, nearest first, as the
     * runtime reads them: each class's own, then those of the traits it uses directly, in order.
     *
     * Each attribute holds its constructor arguments by parameter name, a variadic parameter's as a list.
     * The attributes whose arguments are not all single constants are listed apart, wherever they are.
     *
     * @param list<class-string> $attributeClasses
     * @return array{list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}>, array<class-string, true>}
     */
    public function attributeLevels(ClassReflection $classReflection, array $attributeClasses, ?string $baseClass): array
    {
        $levels = [];
        $unknown = [];

        foreach ([$classReflection, ...$classReflection->getParents()] as $ancestor) {
            if ($ancestor->getName() === $baseClass) {
                break;
            }

            $level = ['class' => $ancestor->getName(), 'own' => [], 'traits' => []];

            foreach ([$ancestor, ...$ancestor->getTraits()] as $source) {
                $attributes = [];

                foreach ($attributeClasses as $attributeClass) {
                    $arguments = $this->attributeArguments($source, $attributeClass);

                    if ($arguments === false) {
                        $unknown[$attributeClass] = true;
                    } elseif ($arguments !== null) {
                        $attributes[$attributeClass] = $arguments;
                    }
                }

                if ($source === $ancestor) {
                    $level['own'] = $attributes;
                } else {
                    $level['traits'][$source->getName()] = $attributes;
                }
            }

            $levels[] = $level;
        }

        return [$levels, $unknown];
    }

    /**
     * Find the nearest of the levels' attributes of a class: a class's own, then its traits' in order.
     *
     * @param list<array{class: string, own: array<class-string, array<string, mixed>>, traits: array<string, array<class-string, array<string, mixed>>>}> $levels
     * @param class-string $attributeClass
     * @return null|array{array<string, mixed>, string} the attribute's arguments and the class the runtime treats as declaring it
     */
    public function nearestAttribute(array $levels, string $attributeClass): ?array
    {
        foreach ($levels as $level) {
            foreach ([$level['own'], ...$level['traits']] as $attributes) {
                if (isset($attributes[$attributeClass])) {
                    return [$attributes[$attributeClass], $level['class']];
                }
            }
        }

        return null;
    }

    /**
     * Track the files a class's property defaults, constructors and read attribute arguments come from.
     *
     * They are not declarations, whose changes alone reanalyse the class's file.
     *
     * @param list<class-string> $attributeClasses
     * @param DependencyTracker&Scope $scope
     */
    public function trackSources(ClassReflection $classReflection, array $attributeClasses, ?string $baseClass, Scope $scope): void
    {
        $files = SourceFiles::forInstance($this->reflectionProvider, $classReflection, $baseClass);

        foreach ([$classReflection, ...$classReflection->getParents()] as $ancestor) {
            if ($ancestor->getName() === $baseClass) {
                break;
            }

            foreach ([$ancestor, ...$ancestor->getTraits()] as $source) {
                foreach ($attributeClasses as $attributeClass) {
                    $files = [...$files, ...SourceFiles::forAttributeArguments($this->reflectionProvider, $source, $attributeClass)];
                }
            }
        }

        foreach ($files as $file) {
            if ($file !== $scope->getFile()) {
                $scope->trackFileDependency($file);
            }
        }
    }

    /**
     * Determine whether two values are the same, comparing enum cases by their case.
     */
    public function same(mixed $first, mixed $second): bool
    {
        if ($first instanceof Type || $second instanceof Type) {
            return $first instanceof Type && $second instanceof Type && $first->equals($second);
        }

        return $first === $second;
    }

    /**
     * Write a value as PHP code.
     */
    public function render(mixed $value): string
    {
        if ($value instanceof Type) {
            $case = $value->getEnumCases()[0];

            return '\\' . $case->getClassName() . '::' . $case->getEnumCaseName();
        }

        if (is_array($value)) {
            $items = [];
            $list = array_is_list($value);

            foreach ($value as $key => $item) {
                $items[] = ($list ? '' : var_export($key, true) . ' => ') . $this->render($item);
            }

            return '[' . implode(', ', $items) . ']';
        }

        return $value === null ? 'null' : var_export($value, true);
    }

    /**
     * Write a declared default as written, when an attribute on the same class takes its value.
     */
    public function printExpression(Expr $expression): string
    {
        return (new Standard)->prettyPrintExpr($expression);
    }

    /**
     * Find a property's declaration in the class's source.
     *
     * Reflection reparses defaults without their positions, and one declaration can hold several properties.
     */
    private function propertyItem(ClassLike $class, string $name): ?PropertyItem
    {
        foreach ($class->getProperty($name)->props ?? [] as $item) {
            if ($item->name->toString() === $name) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Find the trait the class uses directly that declares the property itself, when exactly one does.
     */
    private function declaringTrait(ClassReflection $classReflection, string $name): ?ClassReflection
    {
        $traits = array_filter($classReflection->getTraits(), static fn (ClassReflection $trait): bool => $trait->hasNativeProperty($name));

        if (count($traits) !== 1) {
            return null;
        }

        $trait = reset($traits);

        foreach ($trait->getTraits(true) as $nested) {
            if ($nested->hasNativeProperty($name)) {
                return null;
            }
        }

        return $trait;
    }

    /**
     * Get an attribute's constructor arguments by parameter name: null when the source does not declare the
     * attribute, and false when an argument is not one constant.
     *
     * @param class-string $attributeClass
     * @return null|array<string, mixed>|false
     */
    private function attributeArguments(ClassReflection $source, string $attributeClass): array|false|null
    {
        $attribute = $source->getNativeReflection()->getAttributes($attributeClass)[0] ?? null;

        if ($attribute === null) {
            return null;
        }

        $parameters = $this->reflectionProvider->getClass($attributeClass)->getNativeReflection()->getConstructor()?->getParameters() ?? [];
        $last = $parameters === [] ? null : $parameters[array_key_last($parameters)];
        $context = InitializerExprContext::fromClassReflection($source);
        $arguments = [];

        foreach ($attribute->getArgumentsExpressions() as $key => $expression) {
            $value = $this->constantValue($this->initializerExprTypeResolver->getType($expression, $context));
            $parameter = null;

            foreach ($parameters as $index => $candidate) {
                if ($key === $index || $key === $candidate->getName()) {
                    $parameter = $candidate;

                    break;
                }
            }

            // The variadic parameter collects the remaining positional arguments and unmatched named ones.
            $parameter ??= $last?->isVariadic() ? $last : null;

            if ($value === null || $parameter === null || ($parameter->isVariadic() && $value[0] instanceof Type)) {
                return false;
            }

            if ($parameter->isVariadic()) {
                $arguments[$parameter->getName()][] = $value[0];
            } else {
                $arguments[$parameter->getName()] = $value[0];
            }
        }

        return $arguments;
    }

    /**
     * Get the one constant value a type holds: a scalar, null, an array of those, or an enum case.
     *
     * @return null|array{mixed} the value, or null when the type is not one such constant
     */
    private function constantValue(Type $type): ?array
    {
        $scalars = $type->getConstantScalarValues();

        if (count($scalars) === 1 && $type->isConstantScalarValue()->yes()) {
            return [$scalars[0]];
        }

        $enumCases = $type->getEnumCases();

        if (count($enumCases) === 1 && $type->isObject()->yes()) {
            return [$enumCases[0]];
        }

        $arrays = $type->getConstantArrays();

        if (count($arrays) !== 1 || ! $type->isConstantArray()->yes() || $arrays[0]->getOptionalKeys() !== []) {
            return null;
        }

        $array = [];

        foreach ($arrays[0]->getKeyTypes() as $index => $keyType) {
            $item = $this->constantValue($arrays[0]->getValueTypes()[$index]);

            if ($item === null || (! is_scalar($item[0]) && $item[0] !== null)) {
                return null;
            }

            $array[$keyType->getValue()] = $item[0];
        }

        return [$array];
    }
}
