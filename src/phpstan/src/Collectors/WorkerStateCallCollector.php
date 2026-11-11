<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Collectors;

use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\ContextualAttribute;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Route;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;

use function array_any;
use function array_map;
use function in_array;
use function is_string;

/**
 * Collects the constant container resolutions that make a class worker-shared, the route closures
 * that handle requests, the helpers a method calls on its own instance, and the config writes
 * through the global repository.
 *
 * @phpstan-import-type ConfigWrite from WorkerStateHelper
 * @phpstan-import-type HelperCall from WorkerStateHelper
 * @phpstan-import-type RouteAction from WorkerStateHelper
 *
 * @implements Collector<CallLike, array{type: 'resolution', abstracts: list<string>}|ConfigWrite|HelperCall|RouteAction>
 */
#[AutowiredService]
class WorkerStateCallCollector implements Collector
{
    /**
     * The repository methods that change configuration.
     */
    private const array CONFIG_WRITES = ['set', 'push', 'prepend', 'offsetset', 'offsetunset'];

    /**
     * The route registration methods whose action parameter takes a route closure.
     */
    private const array ROUTE_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match', 'fallback', 'addroute'];

    /**
     * Create a new collector instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private WorkerStateHelper $helper,
    ) {
    }

    /**
     * Get the node type the collector inspects.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Collect the call's resolved abstracts, route closure, helper or config write.
     *
     * @param CallLike $node
     *
     * @return null|array{type: 'resolution', abstracts: list<string>}|ConfigWrite|HelperCall|RouteAction
     */
    public function processNode(Node $node, Scope $scope): ?array
    {
        if (! $this->helper->isEnabled() || $node->isFirstClassCallable()) {
            return null;
        }

        $abstracts = $this->helper->getResolvedAbstracts($node, $scope);

        if ($abstracts !== null) {
            return ['type' => 'resolution', 'abstracts' => $abstracts];
        }

        $action = $this->getRouteAction($node, $scope);

        if ($action !== null) {
            // A function nested in the action is called on its own terms, so it is not the action.
            $nested = (new NodeFinder)->find(
                $action->getStmts(),
                static fn (Node $node): bool => $node instanceof FunctionLike || $node instanceof ClassLike,
            );

            return [
                'type' => 'action',
                'start' => $action->getStartFilePos(),
                'end' => $action->getEndFilePos(),
                'nested' => array_map(static fn (Node $node): array => [$node->getStartFilePos(), $node->getEndFilePos()], $nested),
                'configParameters' => $this->getConfigParameters($action->params),
            ];
        }

        // A direct call on $this runs the helper for the same instance, in the same operation. A
        // nested function may run later, and creating a callable runs nothing.
        if (
            $node instanceof MethodCall
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && ! $scope->isInAnonymousFunction()
        ) {
            $function = $this->helper->getFunctionFacts($scope, $node);

            return $function === null || $function['class'] === null || $function['method'] === null
                ? null
                : ['type' => 'call', 'class' => $function['class'], 'method' => $function['method'], 'callee' => $node->name->name];
        }

        if (! $this->isConfigWrite($node, $scope)) {
            return null;
        }

        // A repository in a local or a property may be another one.
        $receiver = $node instanceof MethodCall
            ? $this->helper->getConfigReceiver($node->var, $scope)
            : ['abstracts' => null, 'parameter' => null];
        $function = $this->helper->getFunctionFacts($scope, $node);

        if ($receiver === null || $function === null) {
            return null;
        }

        return ['type' => 'config', 'line' => $node->getStartLine(), ...$receiver, 'function' => $function];
    }

    /**
     * Get the closure or arrow function the call registers as a route's action.
     */
    private function getRouteAction(CallLike $call, Scope $scope): ArrowFunction|Closure|null
    {
        if (! $call instanceof MethodCall && ! $call instanceof StaticCall
            || ! $call->name instanceof Identifier
            || ! in_array($call->name->toLowerString(), self::ROUTE_METHODS, true)) {
            return null;
        }

        $type = match (true) {
            $call instanceof MethodCall => $scope->getType($call->var),
            $call->class instanceof Name => $scope->resolveTypeByName($call->class),
            default => null,
        };
        $method = $type === null ? null : $scope->getMethodReflection($type, $call->name->name);
        $class = $method?->getDeclaringClass();

        if ($class === null || ! $class->is(Router::class) && ! $class->is(RouteRegistrar::class) && ! $class->is(Route::class)) {
            return null;
        }

        $parameters = ParametersAcceptorSelector::selectFromArgs($scope, $call->getArgs(), $method->getVariants())->getParameters();

        foreach ($call->getArgs() as $index => $argument) {
            if (($argument->name?->toString() ?? ($parameters[$index] ?? null)?->getName()) === 'action') {
                return $argument->value instanceof Closure || $argument->value instanceof ArrowFunction ? $argument->value : null;
            }
        }

        return null;
    }

    /**
     * Get the route closure parameters the router injects that are typed as a configuration repository,
     * with their classes: those without a default or a contextual attribute.
     *
     * @param array<Param> $parameters
     *
     * @return array<string, string>
     */
    private function getConfigParameters(array $parameters): array
    {
        $classes = [];

        foreach ($parameters as $parameter) {
            $type = $parameter->type instanceof NullableType ? $parameter->type->type : $parameter->type;

            if ($parameter->default === null
                && $type instanceof Name
                && $this->reflectionProvider->hasClass($type->toString())
                && $this->reflectionProvider->getClass($type->toString())->is(Repository::class)
                && $parameter->var instanceof Variable
                && is_string($parameter->var->name)
                && ! $this->hasContextualAttribute($parameter)) {
                $classes[$parameter->var->name] = $type->toString();
            }
        }

        return $classes;
    }

    /**
     * Determine whether a contextual attribute supplies the parameter's value.
     */
    private function hasContextualAttribute(Param $parameter): bool
    {
        foreach ($parameter->attrGroups as $group) {
            if (array_any($group->attrs, fn ($attribute): bool => $this->reflectionProvider->hasClass($attribute->name->toString())
                && $this->reflectionProvider->getClass($attribute->name->toString())->is(ContextualAttribute::class))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the call changes configuration: config() with an array, a Config facade write,
     * or a repository write method.
     */
    private function isConfigWrite(CallLike $call, Scope $scope): bool
    {
        if ($call instanceof FuncCall) {
            $key = $call->getArg('key', 0);

            return $this->helper->isConfigFunction($call, $scope)
                && $key !== null
                && $scope->getType($key->value)->isArray()->yes();
        }

        if (! $call instanceof MethodCall && ! $call instanceof StaticCall
            || ! $call->name instanceof Identifier
            || ! in_array($call->name->toLowerString(), self::CONFIG_WRITES, true)) {
            return false;
        }

        // A method call writes configuration when its receiver is the repository, which the caller checks.
        return ! $call instanceof StaticCall
            || ($call->class instanceof Name
                && (new ObjectType(Config::class))->isSuperTypeOf(new ObjectType($scope->resolveName($call->class)))->yes());
    }
}
