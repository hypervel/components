<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\Attributes\BootOrTestsOnly;
use Hypervel\Support\Attributes\TestsOnly;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Node\CollectedDataNode;
use PHPStan\Reflection\AttributeReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function array_any;
use function array_find;
use function array_map;
use function in_array;
use function is_string;
use function strtolower;

/**
 * Collects the boot-independent facts of the checks that judge code by the request, job or operation
 * it runs in, and builds the shared model the rules apply the booted lifetimes with.
 *
 * @phpstan-type FunctionFacts array{class: null|string, method: null|string, closure: bool, context: null|string, position: int}
 * @phpstan-type ConfigWrite array{type: 'config', line: int, abstracts: null|list<string>, parameter: null|string, property: null|array{string, string}, function: FunctionFacts}
 * @phpstan-type RouteAction array{type: 'action', start: int, end: int, nested: list<array{int, int}>, configParameters: array<string, string>}
 * @phpstan-type HelperCall array{type: 'call', class: string, method: string, callee: string}
 */
#[AutowiredService]
class WorkerStateHelper
{
    use HasContainer;

    /**
     * The lowercase names of the methods that run a queued class as a job.
     */
    public const array JOB_METHODS = ['handle', '__invoke', 'failed'];

    /**
     * The values that belong to one request or operation, keyed by the label diagnostics use.
     */
    private const array STATE_TYPES = [
        'request' => [Request::class, ServerRequestInterface::class],
        'response' => [Response::class, ResponseInterface::class],
        'user' => [Authenticatable::class],
        'file' => [UploadedFile::class],
        'model' => [Model::class],
    ];

    /**
     * The resolving helpers, each with its abstract's parameter name and whether parameters follow.
     */
    private const array RESOLVING_FUNCTIONS = ['app' => ['abstract', true], 'resolve' => ['name', true]];

    /**
     * The resolving container methods, each with its abstract's parameter name and whether parameters follow.
     */
    private const array RESOLVING_METHODS = ['make' => ['abstract', true], 'get' => ['id', false], 'factory' => ['abstract', false]];

    /**
     * The attributes that restrict a method to boot time or tests.
     */
    private const array LIFETIME_ATTRIBUTES = [BootOnly::class, TestsOnly::class, BootOrTestsOnly::class];

    /**
     * The lowercase repository methods that change configuration.
     */
    private const array CONFIG_WRITES = ['set', 'push', 'prepend', 'offsetset', 'offsetunset'];

    /**
     * The last collected data analyzed, and its model.
     *
     * @var null|array{CollectedDataNode, WorkerStateAnalysis}
     */
    private ?array $analysis = null;

    /**
     * Create a new worker state helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private CoroutineRuleHelper $coroutineRuleHelper,
        private RuntimeInputs $runtimeInputs,
        #[AutowiredParameter(ref: '%checkWorkerSharedState%')]
        public readonly bool $checkWorkerSharedState,
        #[AutowiredParameter(ref: '%checkStaticStateMutation%')]
        public readonly bool $checkStaticStateMutation,
        #[AutowiredParameter(ref: '%checkUnboundedWorkerCaches%')]
        public readonly bool $checkUnboundedWorkerCaches,
        #[AutowiredParameter(ref: '%noRuntimeConfigMutation%')]
        public readonly bool $noRuntimeConfigMutation,
        #[AutowiredParameter(ref: '%checkRestrictedCalls%')]
        public readonly bool $checkRestrictedCalls,
        #[AutowiredParameter(ref: '%checkCancellationHandling%')]
        public readonly bool $checkCancellationHandling,
    ) {
    }

    /**
     * Determine whether any check that judges code by the operation it runs in is enabled, so the
     * shared model's facts are collected.
     */
    public function isEnabled(): bool
    {
        return $this->hasStateChecks() || $this->checkRestrictedCalls || $this->checkCancellationHandling;
    }

    /**
     * Determine whether any check of the state code keeps for the worker is enabled.
     */
    public function hasStateChecks(): bool
    {
        return $this->checkWorkerSharedState
            || $this->checkStaticStateMutation
            || $this->checkUnboundedWorkerCaches
            || $this->noRuntimeConfigMutation;
    }

    /**
     * Get the worker-state model of the collected data, built once for all the rules that share it.
     */
    public function analyze(CollectedDataNode $node): WorkerStateAnalysis
    {
        if ($this->analysis === null || $this->analysis[0] !== $node) {
            // The analysis always runs on the framework container: the booted application or a test's own.
            /** @var Container $container */
            $container = $this->getContainer();

            $this->analysis = [$node, new WorkerStateAnalysis($node, $this->reflectionProvider, $container, $this->runtimeInputs)];
        }

        return $this->analysis[1];
    }

    /**
     * Get the facts of the function the scope's code at the node runs in, or null when it is a
     * flushState() method or one marked as boot-time or test-only.
     *
     * The context is the operation the code always runs in when the class alone decides it: a queued
     * class's handle(), __invoke() or failed(), or a form request's method. The rule adds booted
     * middleware and controller actions, the helpers those methods call on their own instance, and
     * route closures by the node's position.
     *
     * @return null|FunctionFacts
     */
    public function getFunctionFacts(Scope $scope, Node $node): ?array
    {
        $function = $scope->getFunction();

        if ($function !== null && ($function->getName() === 'flushState' || $this->getLifetimeAttribute($function) !== null)) {
            return null;
        }

        $class = $scope->getClassReflection();
        $method = $function instanceof MethodReflection ? $function : null;
        $closure = $scope->isInAnonymousFunction();

        $context = match (true) {
            $closure, $class === null, $method === null, $method->isStatic() => null,
            $class->is(ShouldQueue::class) && in_array(strtolower($method->getName()), self::JOB_METHODS, true) => 'job',
            $class->is(FormRequest::class) => 'request',
            default => null,
        };

        return [
            'class' => $class?->getName(),
            'method' => $method?->getName(),
            'closure' => $closure,
            'context' => $context,
            'position' => $node->getStartFilePos(),
        ];
    }

    /**
     * Get the attribute marking the function as running only while the application boots or in tests.
     */
    public function getLifetimeAttribute(FunctionReflection|ExtendedMethodReflection $function): ?AttributeReflection
    {
        return array_find(
            $function->getAttributes(),
            static fn (AttributeReflection $attribute): bool => in_array($attribute->getName(), self::LIFETIME_ATTRIBUTES, true),
        );
    }

    /**
     * Determine whether the type is always a configuration repository, which the rule may prove to be
     * the global one.
     */
    public function isConfigRepositoryType(Type $type): bool
    {
        return (new ObjectType(Repository::class))->isSuperTypeOf(TypeCombinator::removeNull($type))->yes();
    }

    /**
     * Determine whether the call is the global config() helper.
     */
    public function isConfigFunction(FuncCall $call, Scope $scope): bool
    {
        return $call->name instanceof Name
            && strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '') === 'config';
    }

    /**
     * Determine whether the call changes configuration: config() with an array, a Config facade write,
     * or a repository write method.
     */
    public function isConfigWrite(CallLike $call, Scope $scope): bool
    {
        if ($call instanceof FuncCall) {
            $key = $call->getArg('key', 0);

            return $this->isConfigFunction($call, $scope)
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

    /**
     * Describe the expression a configuration write goes through when it may be the global repository:
     * config() without arguments, a constant resolution from the application container (its names,
     * which the rule resolves with the booted aliases), a parameter of the innermost function typed
     * as a repository (its name, which the rule checks the container injects), or a repository
     * property the constructor promotes (which the rule checks the same way). Null for any other
     * expression.
     *
     * @return null|array{abstracts: null|list<string>, parameter: null|string, property: null|array{string, string}}
     */
    public function getConfigReceiver(Expr $expr, Scope $scope): ?array
    {
        if ($expr instanceof FuncCall && $expr->getArgs() === [] && $this->isConfigFunction($expr, $scope)) {
            return ['abstracts' => null, 'parameter' => null, 'property' => null];
        }

        $abstracts = $expr instanceof CallLike ? $this->getApplicationResolvedAbstracts($expr, $scope) : null;

        if ($abstracts !== null) {
            return ['abstracts' => $abstracts, 'parameter' => null, 'property' => null];
        }

        $parameter = $expr instanceof Variable && is_string($expr->name)
            ? array_find($this->getParameters($scope), static fn (ParameterReflection $parameter): bool => $parameter->getName() === $expr->name)
            : null;

        if ($parameter !== null && $this->isConfigRepositoryType($parameter->getType())) {
            return ['abstracts' => null, 'parameter' => $parameter->getName(), 'property' => null];
        }

        $property = $this->isConfigRepositoryType($scope->getType($expr)) ? $this->getPromotedProperty($expr, $scope) : null;

        return $property === null ? null : ['abstracts' => null, 'parameter' => null, 'property' => $property];
    }

    /**
     * Get the constructor's declaring class and the property name when the expression reads $this's
     * readonly property that constructor promotes, outside closures. The property then holds what
     * the container injected into the constructor, when the container built the instance.
     *
     * @return null|array{string, string}
     */
    public function getPromotedProperty(Expr $expr, Scope $scope): ?array
    {
        if (! $expr instanceof PropertyFetch
            || ! $expr->var instanceof Variable
            || $expr->var->name !== 'this'
            || ! $expr->name instanceof Identifier
            || $scope->isInAnonymousFunction()) {
            return null;
        }

        $class = $scope->getClassReflection();
        $name = $expr->name->toString();

        if ($class === null || ! $class->hasNativeProperty($name) || ! $class->hasConstructor()) {
            return null;
        }

        $property = $class->getNativeProperty($name);
        $constructor = $class->getConstructor()->getDeclaringClass()->getName();

        // Only the constructor that runs for the class sets the property it promotes.
        return $property->isPromoted() && $property->isReadOnly() && $property->getDeclaringClass()->getName() === $constructor
            ? [$constructor, $name]
            : null;
    }

    /**
     * Get the parameters of the scope's innermost function.
     *
     * @return list<ParameterReflection>
     */
    public function getParameters(Scope $scope): array
    {
        return $scope->isInAnonymousFunction()
            ? $scope->getAnonymousFunctionReflection()->getParameters()
            : $scope->getFunction()?->getOnlyVariant()->getParameters() ?? [];
    }

    /**
     * Get the abstracts a container resolution without parameters requests, or null for any other call.
     *
     * @return null|list<string>
     */
    public function getResolvedAbstracts(CallLike $call, Scope $scope): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $signature = match (true) {
            $call instanceof FuncCall => $call->name instanceof Name
                ? self::RESOLVING_FUNCTIONS[strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? '')] ?? null
                : null,
            $call instanceof MethodCall => $call->name instanceof Identifier
                && (new ObjectType(ContainerContract::class))->isSuperTypeOf($scope->getType($call->var))->yes()
                ? self::RESOLVING_METHODS[$call->name->toLowerString()] ?? null
                : null,
            $call instanceof StaticCall => $call->class instanceof Name
                && $call->name instanceof Identifier
                && (new ObjectType(App::class))->isSuperTypeOf(new ObjectType($scope->resolveName($call->class)))->yes()
                ? self::RESOLVING_METHODS[$call->name->toLowerString()] ?? null
                : null,
            default => null,
        };

        if ($signature === null || array_any($call->getArgs(), static fn (Arg $argument): bool => $argument->unpack)) {
            return null;
        }

        // Arguments are read by name, as named arguments may come in any order.
        $abstract = $call->getArg($signature[0], 0);
        $parameters = $signature[1] ? $call->getArg('parameters', 1) : null;

        // Parameters bypass the shared caches, so such a resolution makes nothing worker-shared. An empty array does not.
        if ($abstract === null || ($parameters !== null && ! $scope->getType($parameters->value)->isIterableAtLeastOnce()->no())) {
            return null;
        }

        $abstracts = $scope->getType($abstract->value)->getConstantStrings();

        return $abstracts === [] ? null : array_map(static fn ($abstract): string => $abstract->getValue(), $abstracts);
    }

    /**
     * Get the abstracts a resolution from the application container requests, or null for any other
     * call: the resolving helpers, the App facade, or the framework's resolving method called on the
     * application, such as app() or an injected application, or on Container::getInstance().
     *
     * Another container, or one overriding the method, may hold other bindings than the booted ones.
     *
     * @return null|list<string>
     */
    public function getApplicationResolvedAbstracts(CallLike $call, Scope $scope): ?array
    {
        if ($call instanceof MethodCall && ! $this->isApplicationContainerCall($call, $scope)) {
            return null;
        }

        return $this->getResolvedAbstracts($call, $scope);
    }

    /**
     * Determine whether the call runs the framework container's own method on the application: any
     * application instance, such as app() or an injected application, or Container::getInstance().
     *
     * Another container, or one overriding the method, may hold other bindings than the booted ones.
     */
    public function isApplicationContainerCall(MethodCall $call, Scope $scope): bool
    {
        $receiver = $call->var;
        $type = $scope->getType($receiver);

        // A worker runs one application, so any application instance is the booted one.
        $application = (new ObjectType(Application::class))->isSuperTypeOf($type)->yes()
            || ($receiver instanceof StaticCall
                && $receiver->class instanceof Name
                && $receiver->name instanceof Identifier
                && $receiver->name->toLowerString() === 'getinstance'
                && (new ObjectType(Container::class))->isSuperTypeOf(new ObjectType($scope->resolveName($receiver->class)))->yes());

        $method = $application && $call->name instanceof Identifier
            ? $scope->getMethodReflection($type, $call->name->name)
            : null;

        return $method !== null && (new ObjectType($method->getDeclaringClass()->getName()))->isSuperTypeOf(new ObjectType(Container::class))->yes();
    }

    /**
     * Get the label of the request or operation value the type always holds, ignoring null.
     */
    public function getState(Type $type): ?string
    {
        $type = TypeCombinator::removeNull($type);

        if (! $type->isObject()->yes()) {
            return null;
        }

        foreach (self::STATE_TYPES as $label => $classes) {
            foreach ($classes as $class) {
                if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                    return $label;
                }
            }
        }

        return null;
    }

    /**
     * Determine whether the expression reads the current request or user, without entering closures.
     */
    public function readsRequestState(Expr $expr, Scope $scope): bool
    {
        $requestTypes = [...self::STATE_TYPES['request'], ...self::STATE_TYPES['user']];

        return $this->coroutineRuleHelper->find([$expr], function (Node $node) use ($scope, $requestTypes): bool {
            if (! $node instanceof Expr) {
                return false;
            }

            if ($node instanceof FuncCall
                && $node->name instanceof Name
                && in_array(strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? ''), ['request', 'auth'], true)) {
                return true;
            }

            if ($node instanceof StaticCall && $node->class instanceof Name) {
                $class = new ObjectType($scope->resolveName($node->class));

                if ((new ObjectType(RequestFacade::class))->isSuperTypeOf($class)->yes() || (new ObjectType(Auth::class))->isSuperTypeOf($class)->yes()) {
                    return true;
                }
            }

            $type = TypeCombinator::removeNull($scope->getType($node));

            if (! $type->isObject()->yes()) {
                return false;
            }

            foreach ($requestTypes as $class) {
                if ((new ObjectType($class))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }

            return false;
        }) !== [];
    }

    /**
     * Determine whether a key of the type can only take a bounded set of values.
     */
    public function isBoundedKey(Type $type): bool
    {
        foreach (TypeUtils::flattenTypes($type) as $member) {
            $range = TypeUtils::getIntegerRanges($member)[0] ?? null;

            // Bounds come first: PHPStan enumerates a small range value by value, and gives no finite
            // types for a larger one.
            if ($range?->getMin() !== null && $range->getMax() !== null) {
                continue;
            }

            if ($member->getFiniteTypes() === []) {
                return false;
            }
        }

        return true;
    }
}
