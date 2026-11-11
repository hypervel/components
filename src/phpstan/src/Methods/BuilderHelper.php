<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\PHPStan\Reflection\AttributeClassNameResolver;
use Hypervel\PHPStan\Reflection\SourceFiles;
use LogicException;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\OutOfClassScope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use ReflectionNamedType;

use function array_keys;
use function count;
use function in_array;
use function is_string;
use function sprintf;
use function strcasecmp;
use function strtolower;

/**
 * Resolve the builders and queries that Eloquent models forward their calls to, as the runtime does.
 */
#[AutowiredService]
class BuilderHelper
{
    /** @var array<class-string, list<string>> */
    private array $passthru = [];

    /** @var array<string, string|Type> */
    private array $builders = [];

    /** @var array<string, Type> */
    private array $builderTypes = [];

    private readonly OutOfClassScope $scope;

    /**
     * Create a builder helper.
     */
    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly DeclarationDependencyTracker $declarationDependencyTracker,
        private readonly AttributeClassNameResolver $attributeClassNames,
    ) {
        $this->scope = new OutOfClassScope;
    }

    /**
     * Determine whether the builder passes the method through to its base query.
     */
    public function isPassthru(ClassReflection $builderClass, string $methodName): bool
    {
        $className = $builderClass->getName();

        if (! isset($this->passthru[$className])) {
            /** @var list<string> $passthru */
            $passthru = $builderClass->getNativeReflection()->getDefaultProperties()['passthru'];

            $this->passthru[$className] = $passthru;
        }

        return in_array(strtolower($methodName), $this->passthru[$className], strict: true);
    }

    /**
     * Return the declared types of the queries a builder forwards to: its stored query, which ordinary
     * forwarded calls run on, and the query passthru calls run on.
     *
     * Builder::__call() passes passthru calls to toBase() and forwards others to the stored query. Read
     * through getMethod(), so a `@method` tag counts, as packages replacing the query layer (such as
     * mongodb/laravel-mongodb) declare it on toBase().
     *
     * @return array{Type, Type}
     */
    public function queryTypes(ClassReflection $builderClass): array
    {
        $storedType = $builderClass->getMethod('getQuery', $this->scope)->getVariants()[0]->getReturnType();
        $baseType = $builderClass->getMethod('toBase', $this->scope)->getVariants()[0]->getReturnType();

        // The inherited toBase() returns the scoped stored query, so its declaration describes that query too.
        if ($builderClass->getNativeMethod('toBase')->getDeclaringClass()->getName() === EloquentBuilder::class) {
            $storedType = TypeCombinator::intersect($storedType, $baseType);

            return [$storedType, $storedType];
        }

        return [$storedType, $baseType];
    }

    /**
     * Bind a forwarded query's row types to the model, without changing raw-query types.
     */
    public function bindQueryRows(Type $queryType, Type $modelType): Type
    {
        $queryClasses = $queryType->getObjectClassReflections();

        if (count($queryClasses) !== 1) {
            return $queryType;
        }

        $queryClass = $queryClasses[0];
        $templates = $queryClass->getTemplateTypeMap();

        if ($templates->getType('TKey') === null || $templates->getType('TValue') === null) {
            return $queryType;
        }

        $types = $queryClass->getActiveTemplateTypeMap()->map(
            static fn (string $name, Type $type): Type => match ($name) {
                'TKey' => new IntegerType,
                'TValue' => $modelType,
                default => $type,
            },
        );

        return new GenericObjectType($queryClass->getName(), $queryClass->typeMapToList($types));
    }

    /**
     * Return the builder type holding the model, as Model::newModelQuery() binds it through setModel().
     *
     * setModel() rebinds only the template mapped to the Eloquent builder's model, so other type arguments
     * and a builder that fixes its model keep their declarations.
     */
    public function getBuilderType(Type $builderType, Type $modelType): Type
    {
        // The base builder holds the model as given. Stub validation relies on this too: it reflects only the
        // stubs, whose Builder declares no setModel().
        if ($builderType->getObjectClassNames() === [EloquentBuilder::class]) {
            return new GenericObjectType(EloquentBuilder::class, [$modelType]);
        }

        // Every builder-of<static> resolution binds a model, so the inference is memoized.
        $cacheKey = $builderType->describe(VerbosityLevel::cache()) . "\0" . $modelType->describe(VerbosityLevel::cache());

        return $this->builderTypes[$cacheKey] ??= ParametersAcceptorSelector::selectFromTypes(
            [$modelType],
            $builderType->getMethod('setModel', $this->scope)->getVariants(),
            false,
        )->getReturnType();
    }

    /**
     * Determine the model's builder as Model::newEloquentBuilder() does: an override's declared return, else a
     * builder named by the UseEloquentBuilder attribute, else the $builder default (which upstream ignores).
     */
    private function determineBuilder(ClassReflection $modelReflection): string|Type
    {
        // The base model, which stands for an unknown model, creates the base builder. Stub validation
        // relies on this too: it reflects only the stubs, whose Model declares no newEloquentBuilder().
        if ($modelReflection->getName() === Model::class) {
            return EloquentBuilder::class;
        }

        // Forwarded calls run through newQuery(). HasBuilder's declares the builder its @use names, even when another
        // trait's newEloquentBuilder() wins the conflict, as Nested Set's HasNode does. Its origin is checked by file
        // first: reflecting an undocumented override resolves its inherited builder-of<static>, which calls back here.
        if ($modelReflection->getNativeReflection()->getMethod('newQuery')->getFileName()
            === $this->reflectionProvider->getClass(HasBuilder::class)->getFileName()) {
            return $modelReflection->getNativeMethod('newQuery')->getVariants()[0]->getReturnType();
        }

        // Every query entry point runs newModelQuery(), so an override naming a builder decides. Only its native
        // return is read: an undocumented override inherits builder-of<static>, which calls back here, so a
        // PHPDoc-only narrowing is not seen.
        $newModelQuery = $modelReflection->getNativeReflection()->getMethod('newModelQuery');
        $returnType = $newModelQuery->getReturnType();

        if ($newModelQuery->getDeclaringClass()->getName() !== Model::class
            && $returnType instanceof ReflectionNamedType
            && $this->reflectionProvider->hasClass($returnType->getName())
            && $this->reflectionProvider->getClass($returnType->getName())->isSubclassOfClass($this->reflectionProvider->getClass(EloquentBuilder::class))) {
            return $returnType->getName();
        }

        $method = $modelReflection->getNativeMethod('newEloquentBuilder');

        // The override decides on its own, so its declared return is kept even when it is broader than the attribute.
        if ($method->getDeclaringClass()->getName() !== Model::class) {
            return $method->getVariants()[0]->getReturnType();
        }

        $builderClassName = $this->findUseEloquentBuilderClassName($modelReflection);

        // Like Model::newEloquentBuilder(), ignore a class that is not an Eloquent builder subclass.
        if ($builderClassName !== null
            && $this->reflectionProvider->hasClass($builderClassName)
            && $this->reflectionProvider->getClass($builderClassName)->isSubclassOfClass($this->reflectionProvider->getClass(EloquentBuilder::class))) {
            return $builderClassName;
        }

        // A property default is not part of the model's declarations.
        foreach (SourceFiles::forInstance($this->reflectionProvider, $modelReflection, Model::class) as $file) {
            $this->declarationDependencyTracker->trackFileDependency($modelReflection, $file);
        }

        $builderClassName = $modelReflection->getNativeReflection()->getDefaultProperties()['builder'];

        return is_string($builderClassName) && $this->reflectionProvider->hasClass($builderClassName)
            ? $builderClassName
            : EloquentBuilder::class;
    }

    /**
     * Mirror Model::resolveCustomBuilderClass(): the model, its traits, then each parent and its traits, nearest first.
     *
     * It instantiates the attribute, so the argument may also be named.
     */
    private function findUseEloquentBuilderClassName(ClassReflection $modelReflection): ?string
    {
        foreach ([$modelReflection, ...$modelReflection->getParents()] as $classReflection) {
            foreach ([$classReflection, ...$classReflection->getTraits()] as $sourceReflection) {
                $attributes = $sourceReflection->getNativeReflection()->getAttributes(UseEloquentBuilder::class);

                if ($attributes === []) {
                    continue;
                }

                $arguments = $attributes[0]->getArgumentsExpressions();

                return $this->attributeClassNames->resolve($arguments[0] ?? $arguments['builderClass'] ?? null, $sourceReflection, $modelReflection);
            }
        }

        return null;
    }

    /**
     * Return the builder type the model creates, with the given model type bound to it.
     */
    public function determineBuilderClass(string $modelClassName, Type $modelType): ?Type
    {
        // Unlike upstream, which crashes, a missing class is left to PHPStan's class.notFound report.
        if (! $this->reflectionProvider->hasClass($modelClassName)) {
            return null;
        }

        $modelReflection = $this->reflectionProvider->getClass($modelClassName);

        if (! $modelReflection->is(Model::class)) {
            return null;
        }

        $builder = $this->builders[$modelClassName] ??= $this->determineBuilder($modelReflection);

        return $this->getBuilderType(is_string($builder) ? new ObjectType($builder) : $builder, $modelType);
    }

    /**
     * Return an active template type from a class ancestor.
     *
     * @param class-string $ancestorClass
     */
    public function templateType(ClassReflection $classReflection, string $ancestorClass, string $templateName): Type
    {
        $type = $classReflection
            ->getAncestorWithClassName($ancestorClass)
            ?->getActiveTemplateTypeMap()
            ->getType($templateName);

        return $type ?? throw new LogicException(sprintf(
            'Template type [%s] is not available for [%s].',
            $templateName,
            $classReflection->getName(),
        ));
    }

    /**
     * Determine whether a class owns a native or documented method, which a forwarded method would not replace.
     */
    public function hasNativeOrDocumentedMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if ($classReflection->hasNativeMethod($methodName)) {
            return true;
        }

        foreach (array_keys($classReflection->getMethodTags()) as $documentedMethod) {
            if (strcasecmp($documentedMethod, $methodName) === 0) {
                return true;
            }
        }

        return false;
    }
}
