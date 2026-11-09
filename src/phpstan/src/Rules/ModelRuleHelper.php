<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\PHPStan\Methods\BuilderHelper;
use Hypervel\PHPStan\Methods\ModelScopeMethodResolver;
use Hypervel\PHPStan\Properties\ModelCastHelper;
use Hypervel\PHPStan\Properties\ModelPropertyHelper;
use Hypervel\PHPStan\Reflection\SourceFiles;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use ReflectionAttribute;

use function array_all;
use function array_keys;
use function array_values;
use function class_basename;
use function in_array;
use function lcfirst;
use function strtolower;
use function substr;

/**
 * Share how the model rules read models, including the model a call on a model, builder or relation queries.
 *
 * Unlike upstream, the queried model comes from the named Builder or Relation template, so custom builders with
 * other type parameters, and non-generic builders whose ancestor fixes the model, resolve too.
 */
#[AutowiredService]
class ModelRuleHelper
{
    /**
     * The model methods that build its queries.
     */
    private const array QUERY_METHODS = [
        '__callStatic',
        '__call',
        'newQuery',
        'newQueryWithoutScopes',
        'registerGlobalScopes',
        'newModelQuery',
        'newEloquentBuilder',
        'newBaseQueryBuilder',
    ];

    /**
     * Whether each model builds plain queries, by class name.
     *
     * @var array<string, bool>
     */
    private array $plainQueries = [];

    /**
     * Create a new model rule helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private BuilderHelper $builderHelper,
        private ModelPropertyHelper $modelPropertyHelper,
        private ModelCastHelper $modelCastHelper,
    ) {
    }

    /**
     * Return the type the method is called on, with a class-string receiver as its object type.
     */
    public function callerType(MethodCall|StaticCall $node, Scope $scope): Type
    {
        return match (true) {
            $node instanceof MethodCall => $scope->getType($node->var),
            $node->class instanceof Name => $scope->resolveTypeByName($node->class),
            default => $scope->getType($node->class)->getObjectTypeOrClassStringObjectType(),
        };
    }

    /**
     * Get the exact classes a static call runs on, or null when a subclass could be called instead.
     *
     * @return null|list<ClassReflection>
     */
    public function exactCalledClasses(StaticCall $call, Scope $scope): ?array
    {
        $class = $call->class;

        if ($class instanceof Name) {
            // self, static and parent forward late static binding, so only a final calling class is exact.
            if ($class->isSpecialClassName()) {
                $classReflection = $scope->getClassReflection();

                return $classReflection?->isFinal() ? [$classReflection] : null;
            }

            return $this->reflectionProvider->hasClass($class->toString())
                ? [$this->reflectionProvider->getClass($class->toString())]
                : null;
        }

        $type = $scope->getType($class);

        if ($type->isConstantValue()->yes()) {
            $classes = [];

            foreach ($type->getConstantStrings() as $constantString) {
                if (! $this->reflectionProvider->hasClass($constantString->getValue())) {
                    return null;
                }

                $classes[] = $this->reflectionProvider->getClass($constantString->getValue());
            }

            return $classes === [] ? null : $classes;
        }

        $type = $type->getObjectTypeOrClassStringObjectType();
        $classes = $type->getObjectClassReflections();

        return $type->isObject()->yes() && $classes !== [] && array_all($classes, static fn (ClassReflection $class): bool => $class->isFinal())
            ? $classes
            : null;
    }

    /**
     * Return the model a builder, relation or model queries.
     */
    public function queriedModel(ClassReflection $callerClass, Type $callerType): ?Type
    {
        return match (true) {
            $callerClass->is(Relation::class) => $callerType->getTemplateType(Relation::class, 'TRelatedModel'),
            $callerClass->is(EloquentBuilder::class) => $callerType->getTemplateType(EloquentBuilder::class, 'TModel'),
            $callerClass->is(Model::class) => $callerType,
            default => null,
        };
    }

    /**
     * Determine whether a concrete model builds its queries as the base Model does: through Model's query
     * methods and the base builder, without any global scope.
     *
     * Global scopes may add any clause, and boot code can register them, so a model with boot or initialize
     * hooks below Model (found as bootTraits() finds them), #[ScopedBy], a scope in the booted registry or a
     * boot listener is not plain.
     */
    public function buildsPlainQueries(ClassReflection $modelClass): bool
    {
        return $this->plainQueries[$modelClass->getName()] ??= $this->resolvePlainQueries($modelClass);
    }

    /**
     * Resolve whether a concrete model builds plain queries.
     */
    private function resolvePlainQueries(ClassReflection $modelClass): bool
    {
        if ($modelClass->isAbstract() || ! $this->usesModelMethods($modelClass, self::QUERY_METHODS)) {
            return false;
        }

        $bootMethods = [];
        $initializeMethods = [];

        foreach (array_keys($modelClass->getTraits(true)) as $trait) {
            $bootMethods['boot' . class_basename($trait)] = true;
            $initializeMethods['initialize' . class_basename($trait)] = true;
        }

        foreach ($modelClass->getNativeReflection()->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === Model::class) {
                continue;
            }

            if (in_array(strtolower($method->getName()), ['booting', 'boot', 'booted'], true)
                || ($method->isStatic() && (isset($bootMethods[$method->getName()]) || $method->getAttributes(Boot::class) !== []))
                || isset($initializeMethods[$method->getName()])
                || $method->getAttributes(Initialize::class) !== []) {
                return false;
            }
        }

        $builder = $this->builderHelper->determineBuilderClass($modelClass->getName(), new ObjectType($modelClass->getName()));

        if ($builder?->getObjectClassNames() !== [EloquentBuilder::class]
            || (Model::getAllGlobalScopes()[$modelClass->getName()] ?? []) !== []) {
            return false;
        }

        // Like HasGlobalScopes::resolveGlobalScopeAttributes(), a subclass of ScopedBy declares scopes too.
        for ($class = $modelClass; $class !== null; $class = $class->getParentClass()) {
            foreach ([$class, ...array_values($class->getTraits())] as $source) {
                if ($source->getNativeReflection()->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                    return false;
                }
            }
        }

        $dispatcher = Model::getEventDispatcher();

        return $dispatcher === null
            || (! $dispatcher->hasListeners('eloquent.booting: ' . $modelClass->getName())
                && ! $dispatcher->hasListeners('eloquent.booted: ' . $modelClass->getName()));
    }

    /**
     * Determine whether the model runs Model's own implementation of each method, which a subclass or trait may
     * override.
     *
     * @param list<string> $methods
     */
    public function usesModelMethods(ClassReflection $modelClass, array $methods): bool
    {
        return array_all(
            $methods,
            static fn (string $method): bool => $modelClass->getNativeMethod($method)->getDeclaringClass()->getName() === Model::class,
        );
    }

    /**
     * Determine whether reading the property runs a get accessor or a class cast, which can compute it from other
     * attributes, as HasAttributes::transformModelValue() and mutateAttributeForArray() read it.
     */
    public function isComputedProperty(ClassReflection $modelClass, string $name): bool
    {
        if ($this->modelPropertyHelper->getGetMutatorType($modelClass, $name) !== null
            || $this->modelPropertyHelper->getAttributeGetMutatorType($modelClass, $name) !== null) {
            return true;
        }

        if (! $this->modelCastHelper->hasCastForProperty($modelClass, $name)) {
            return false;
        }

        $cast = $this->modelCastHelper->getCastForProperty($modelClass, $name);

        // A cast that cannot be determined may be a class cast.
        if ($cast === null) {
            return true;
        }

        $castClass = $this->modelCastHelper->getCastClass($cast);

        // An enum cast reads the value alone.
        return $castClass !== null && ! $castClass->isEnum();
    }

    /**
     * Get the default a class declares for one of its own properties, from its source.
     *
     * Reflection reparses defaults without their positions, and one declaration can hold several properties.
     */
    public function declaredPropertyDefault(ClassLike $class, string $name): ?Expr
    {
        foreach ($class->getProperty($name)->props ?? [] as $property) {
            if ($property->name->toString() === $name) {
                return $property->default;
            }
        }

        return null;
    }

    /**
     * Determine whether a parent class or interface declares the method, whose name and visibility the model keeps.
     */
    public function declaresInheritedMethod(ClassReflection $modelClass, string $methodName): bool
    {
        foreach ([$modelClass->getParentClass(), ...$modelClass->getInterfaces()] as $ancestor) {
            if ($ancestor?->hasNativeMethod($methodName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a model method is a legacy scope: Model::hasNamedScope() finds it by its name, and its first
     * parameter takes the builder, unlike a helper whose name only starts with "scope", such as scopeExistsIn().
     */
    public function isLegacyScopeMethod(ExtendedMethodReflection $method): bool
    {
        $parameter = $method->getVariants()[0]->getParameters()[0] ?? null;

        return ModelScopeMethodResolver::isLegacyScopeName($method->getName())
            && $parameter !== null
            && ! $parameter->getNativeType()->isSuperTypeOf(new ObjectType(EloquentBuilder::class))->no();
    }

    /**
     * Return the name of the attributed scope that can replace a legacy scope method.
     *
     * A model method already using that name keeps the legacy scope, which is then the only way to keep the scope's
     * name, as DatabaseNotification's scopeRead() sits beside read().
     */
    public function attributedScopeName(ClassReflection $modelClass, ExtendedMethodReflection $method): ?string
    {
        if (! $this->isLegacyScopeMethod($method)) {
            return null;
        }

        $scopeName = lcfirst(substr($method->getName(), 5));

        return $modelClass->hasNativeMethod($scopeName) ? null : $scopeName;
    }

    /**
     * Track the files a model's property defaults and method bodies come from, for a rule checking the model's class.
     *
     * They are not declarations, whose changes alone reanalyse the model's file.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function trackModelSources(ClassReflection $modelClass, Scope $scope): void
    {
        foreach (SourceFiles::forInstance($this->reflectionProvider, $modelClass, Model::class) as $file) {
            if ($file !== $scope->getFile()) {
                $scope->trackFileDependency($file);
            }
        }
    }
}
