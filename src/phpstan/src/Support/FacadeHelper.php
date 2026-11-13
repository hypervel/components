<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Container\SelfBuilding;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Rules\Coroutine\WorkerLifetimes;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\Hash;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureNode;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use ReflectionFunction;

use function array_any;
use function array_key_exists;
use function count;
use function in_array;
use function is_object;

/**
 * Finds the method a facade's static call runs without running application code: neither the
 * accessor nor the root is executed.
 *
 * The root's class is read from the booted container only where a registration shows the exact
 * class built. Factories, extenders and self-building classes can return other objects, so beyond
 * a factory that is a single `new` they leave the root unknown.
 *
 * @internal
 */
#[AutowiredService]
class FacadeHelper
{
    use HasContainer;

    /**
     * The accessor of each facade, or null when it is not a constant.
     *
     * @var array<string, null|string>
     */
    private array $accessors = [];

    /**
     * The root class of each facade, or null when it is unknown, with the classes whose declarations decided it.
     *
     * @var array<string, array{null|string, list<string>}>
     */
    private array $roots = [];

    /**
     * Create a new facade helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
        private RuntimeInputs $runtimeInputs,
    ) {
    }

    /**
     * Get the container key the facade resolves, or null when its accessor does not return one constant.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getAccessor(ClassReflection $facade, Scope $scope): ?string
    {
        $this->runtimeInputs->trackSources($scope, $facade->getName(), Facade::class);

        if (array_key_exists($facade->getName(), $this->accessors)) {
            return $this->accessors[$facade->getName()];
        }

        return $this->accessors[$facade->getName()] = $this->findAccessor($facade);
    }

    /**
     * Get the exact class the facade's root is built as, or null when it is not known.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getRootClass(ClassReflection $facade, Scope $scope): ?string
    {
        $accessor = $this->getAccessor($facade, $scope);

        $this->runtimeInputs->track($scope);

        [$root, $classes] = $this->roots[$facade->getName()] ??= $this->resolveRootClass($facade, $accessor);

        foreach ($classes as $class) {
            $this->runtimeInputs->trackClass($scope, $class);
        }

        return $root;
    }

    /**
     * Get the method a static call on the facade runs: the facade's own public method, else its
     * root's, or null when the root or the facade's forwarding is unknown.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getMethod(ClassReflection $facade, string $name, Scope $scope): ?ExtendedMethodReflection
    {
        if ($facade->hasNativeMethod($name) && $facade->getNativeMethod($name)->isPublic()) {
            return $facade->getNativeMethod($name);
        }

        $root = $this->getRootClass($facade, $scope);

        // Another __callStatic() can forward the call anywhere. Hash's and Crypt's forward it to the
        // root as Facade's does, keeping the arguments out of stack traces.
        if ($root === null
            || ! in_array($facade->getNativeMethod('__callStatic')->getDeclaringClass()->getName(), [Facade::class, Hash::class, Crypt::class], true)) {
            return null;
        }

        $class = $this->reflectionProvider->getClass($root);

        // __callStatic() calls the root from outside, so only a public method is reachable.
        return $class->hasNativeMethod($name) && $class->getNativeMethod($name)->isPublic()
            ? $class->getNativeMethod($name)
            : null;
    }

    /**
     * Get the method the call runs on each class it may be made on.
     *
     * A static call on a facade runs the facade's own method or its root's.
     *
     * @param DependencyTracker&Scope $scope
     *
     * @return list<ExtendedMethodReflection>
     */
    public function getCalledMethods(MethodCall|StaticCall $call, string $name, Scope $scope): array
    {
        $classNames = match (true) {
            ! $call instanceof StaticCall => $scope->getType($call->var)->getObjectClassNames(),
            $call->class instanceof Name => [$scope->resolveName($call->class)],
            default => $scope->getType($call->class)->getObjectTypeOrClassStringObjectType()->getObjectClassNames(),
        };

        $methods = [];

        foreach ($classNames as $className) {
            if (! $this->reflectionProvider->hasClass($className)) {
                continue;
            }

            $class = $this->reflectionProvider->getClass($className);

            $method = match (true) {
                $call instanceof StaticCall && $class->is(Facade::class) => $this->getMethod($class, $name, $scope),
                $class->hasMethod($name) => $class->getMethod($name, $scope),
                default => null,
            };

            if ($method !== null) {
                $methods[] = $method;
            }
        }

        return $methods;
    }

    /**
     * Find the accessor the facade's nearest getFacadeAccessor() returns as a string or class constant.
     */
    private function findAccessor(ClassReflection $facade): ?string
    {
        $method = $facade->getNativeReflection()->getMethod('getFacadeAccessor');

        // The base accessor throws.
        if ($method->getDeclaringClass()->getName() === Facade::class) {
            return null;
        }

        return $this->getLiteralString(
            $this->getReturnedExpression($method->getFileName(), $method->getStartLine(), $method->getEndLine(), [ClassMethod::class]),
        );
    }

    /**
     * Resolve the class the facade's root is built as, with the classes whose declarations decided it.
     *
     * @return array{null|string, list<string>}
     */
    private function resolveRootClass(ClassReflection $facade, ?string $accessor): array
    {
        $resolver = $facade->getNativeMethod('resolveFacadeInstance')->getDeclaringClass()->getName();

        if ($accessor === null
            || ($resolver !== Facade::class && $resolver !== Date::class)
            || $facade->getNativeMethod('getFacadeRoot')->getDeclaringClass()->getName() !== Facade::class) {
            return [null, []];
        }

        // The analysis always runs on the framework container: the booted application or a test's own.
        /** @var Container $container */
        $container = $this->getContainer();

        // Date constructs its default factory directly while its accessor is unbound. The constant's
        // value is read from its declaration, as a typed constant's value type is its declared type.
        if ($resolver === Date::class && ! $container->bound($accessor)) {
            $default = $this->getLiteralString($facade->getConstant('DEFAULT_FACADE')->getValueExpr());

            return $default === null ? [null, []] : $this->getExactClass($default);
        }

        [$instances, $autoSingletons, $extenders] = (fn (): array => [
            $this->instances,
            $this->autoSingletons,
            $this->extenders,
        ])->call($container);

        $bindings = $container->getBindings();
        $abstract = $container->getAlias($accessor);

        // Only the facade's own make() returns an auto-singleton. A concrete reached through a
        // binding is built again, as resolve() skips the implicit cache for it.
        if (isset($autoSingletons[$abstract])) {
            return $this->getExactClass($autoSingletons[$abstract]::class);
        }

        $visited = [];

        while (! isset($visited[$abstract])) {
            $visited[$abstract] = true;

            // extend() replaces a stored instance directly, so it is still the exact object.
            if (array_key_exists($abstract, $instances)) {
                return is_object($instances[$abstract]) ? $this->getExactClass($instances[$abstract]::class) : [null, []];
            }

            // resolve() passes what it builds through the extenders, which can return another object.
            if (($extenders[$abstract] ?? []) !== []) {
                return [null, []];
            }

            $binding = $bindings[$abstract] ?? null;

            if ($binding === null) {
                return $this->getBuiltClass($abstract);
            }

            $concrete = WorkerLifetimes::getBindingConcrete($binding['concrete']);

            if ($concrete === null) {
                return $this->getFactoryClass($binding['concrete']);
            }

            if ($concrete === $abstract) {
                return $this->getBuiltClass($abstract);
            }

            $abstract = $container->getAlias($concrete);
        }

        // The container fails on a binding cycle.
        return [null, []];
    }

    /**
     * Get the class the container builds for the name itself, when it is buildable and not self-building.
     *
     * A self-building class is built through its newInstance(), which may return a subclass.
     *
     * @return array{null|string, list<string>}
     */
    private function getBuiltClass(string $name): array
    {
        if (! $this->reflectionProvider->hasClass($name)) {
            return [null, []];
        }

        $class = $this->reflectionProvider->getClass($name);

        return WorkerLifetimes::isBuildableClass($class) && ! $class->is(SelfBuilding::class)
            ? [$class->getName(), [$class->getName()]]
            : [null, [$class->getName()]];
    }

    /**
     * Get the class a factory closure constructs when its whole body is one `new` of a named class.
     *
     * @return array{null|string, list<string>}
     */
    private function getFactoryClass(Closure $factory): array
    {
        $reflection = new ReflectionFunction($factory);

        $returned = $this->getReturnedExpression(
            $reflection->getFileName(),
            $reflection->getStartLine(),
            $reflection->getEndLine(),
            [ClosureNode::class, ArrowFunction::class],
        );

        // The name resolver makes every named class fully qualified, leaving self, static and parent relative.
        return $returned instanceof New_ && $returned->class instanceof FullyQualified
            ? $this->getExactClass($returned->class->toString())
            : [null, []];
    }

    /**
     * Get a class known to be constructed exactly, when the analysis knows it.
     *
     * @return array{null|string, list<string>}
     */
    private function getExactClass(string $name): array
    {
        if (! $this->reflectionProvider->hasClass($name)) {
            return [null, []];
        }

        $name = $this->reflectionProvider->getClass($name)->getName();

        return [$name, [$name]];
    }

    /**
     * Get the string a string literal or `X::class` expression gives, or null for any other expression.
     */
    private function getLiteralString(?Expr $expr): ?string
    {
        // The name resolver makes every named class fully qualified, leaving self, static and parent relative.
        return match (true) {
            $expr instanceof String_ => $expr->value,
            $expr instanceof ClassConstFetch
                && $expr->class instanceof FullyQualified
                && $expr->name instanceof Identifier
                && $expr->name->toLowerString() === 'class' => $expr->class->toString(),
            default => null,
        };
    }

    /**
     * Get the expression a function returns when its body is a single return, finding the function in
     * its file by its lines.
     *
     * Reflection gives lines but no columns, so the function is found only when exactly one of the
     * given kinds spans those lines.
     *
     * @param list<class-string<FunctionLike>> $kinds
     */
    private function getReturnedExpression(false|string $file, false|int $startLine, false|int $endLine, array $kinds): ?Expr
    {
        if ($file === false || $startLine === false || $endLine === false) {
            return null;
        }

        $functions = (new NodeFinder)->find(
            $this->parser->parseFile($file),
            static fn (Node $node): bool => $node->getStartLine() === $startLine
                && $node->getEndLine() === $endLine
                && array_any($kinds, static fn (string $kind): bool => $node instanceof $kind),
        );

        if (count($functions) !== 1 || ! $functions[0] instanceof FunctionLike) {
            return null;
        }

        $statements = $functions[0]->getStmts() ?? [];

        return count($statements) === 1 && $statements[0] instanceof Return_ ? $statements[0]->expr : null;
    }
}
