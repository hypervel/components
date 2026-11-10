<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Support\Facades\App;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
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

use function array_find;
use function array_map;
use function count;
use function in_array;
use function is_string;
use function preg_match;
use function strtolower;

/**
 * Collects the boot-independent facts of the worker-state checks; WorkerStateRule applies the booted
 * lifetimes to them.
 *
 * @phpstan-type FunctionFacts array{class: null|string, method: null|string, closure: bool, context: null|string, position: int}
 * @phpstan-type ConfigWrite array{type: 'config', line: int, abstracts: null|list<string>, parameter: null|string, function: FunctionFacts}
 * @phpstan-type RouteAction array{type: 'action', start: int, end: int, nested: list<array{int, int}>, configParameters: array<string, string>}
 */
#[AutowiredService]
class WorkerStateHelper
{
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
     * The documented warning tags of boot-time and test-only mutators.
     */
    private const string LIFETIME_TAG_PATTERN = '/^\s*(?:\/\*\*|\*)?\s*(?:Boot-only|Tests only|Boot or tests only)\b/m';

    /**
     * Create a new worker state helper instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private CoroutineRuleHelper $coroutineRuleHelper,
        #[AutowiredParameter(ref: '%checkWorkerSharedState%')]
        public readonly bool $checkWorkerSharedState,
        #[AutowiredParameter(ref: '%checkStaticStateMutation%')]
        public readonly bool $checkStaticStateMutation,
        #[AutowiredParameter(ref: '%checkUnboundedWorkerCaches%')]
        public readonly bool $checkUnboundedWorkerCaches,
        #[AutowiredParameter(ref: '%noRuntimeConfigMutation%')]
        public readonly bool $noRuntimeConfigMutation,
    ) {
    }

    /**
     * Determine whether any worker-state check is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->checkWorkerSharedState
            || $this->checkStaticStateMutation
            || $this->checkUnboundedWorkerCaches
            || $this->noRuntimeConfigMutation;
    }

    /**
     * Get the facts of the function the scope's code at the node runs in, or null when it is a
     * flushState() method or one documented as boot-time or test-only.
     *
     * The context is the operation the code always runs in when the class alone decides it: a queued
     * class's handle(), __invoke() or failed(), or a form request's method. The rule adds booted
     * middleware and controller actions, and route closures by the node's position.
     *
     * @return null|FunctionFacts
     */
    public function getFunctionFacts(Scope $scope, Node $node): ?array
    {
        $function = $scope->getFunction();

        if ($function !== null && ($function->getName() === 'flushState' || $this->hasLifetimeTag($function->getDocComment()))) {
            return null;
        }

        $class = $scope->getClassReflection();
        $method = $function instanceof MethodReflection ? $function : null;
        $closure = $scope->isInAnonymousFunction();

        $context = match (true) {
            $closure, $class === null, $method === null, $method->isStatic() => null,
            $class->is(ShouldQueue::class) && in_array(strtolower($method->getName()), ['handle', '__invoke', 'failed'], true) => 'job',
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
     * Determine whether the docblock documents its method as boot-time or test-only.
     */
    public function hasLifetimeTag(?string $docComment): bool
    {
        return preg_match(self::LIFETIME_TAG_PATTERN, $docComment ?? '') === 1;
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
     * Describe the expression a configuration write goes through when it may be the global repository:
     * config() without arguments, a constant container resolution (its names, which the rule resolves
     * with the booted aliases), or a parameter of the innermost function typed as a repository (its
     * name, which the rule checks the container injects). Null for any other expression.
     *
     * @return null|array{abstracts: null|list<string>, parameter: null|string}
     */
    public function getConfigReceiver(Expr $expr, Scope $scope): ?array
    {
        if ($expr instanceof FuncCall && $expr->getArgs() === [] && $this->isConfigFunction($expr, $scope)) {
            return ['abstracts' => null, 'parameter' => null];
        }

        $abstracts = $expr instanceof CallLike ? $this->getResolvedAbstracts($expr, $scope) : null;

        if ($abstracts !== null) {
            return ['abstracts' => $abstracts, 'parameter' => null];
        }

        $parameter = $expr instanceof Variable && is_string($expr->name)
            ? array_find($this->getParameters($scope), static fn (ParameterReflection $parameter): bool => $parameter->getName() === $expr->name)
            : null;

        return $parameter !== null && $this->isConfigRepositoryType($parameter->getType())
            ? ['abstracts' => null, 'parameter' => $parameter->getName()]
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

        $arguments = $call->getArgs();

        $resolves = match (true) {
            $call instanceof FuncCall => $call->name instanceof Name
                && in_array(strtolower($this->reflectionProvider->resolveFunctionName($call->name, $scope) ?? ''), ['app', 'resolve'], true),
            $call instanceof MethodCall => $call->name instanceof Identifier
                && in_array($call->name->toLowerString(), ['make', 'get', 'factory'], true)
                && (new ObjectType(Container::class))->isSuperTypeOf($scope->getType($call->var))->yes(),
            $call instanceof StaticCall => $call->class instanceof Name
                && $call->name instanceof Identifier
                && in_array($call->name->toLowerString(), ['make', 'get', 'factory'], true)
                && (new ObjectType(App::class))->isSuperTypeOf(new ObjectType($scope->resolveName($call->class)))->yes(),
            default => false,
        };

        // Parameters bypass the shared caches, so such a resolution makes nothing worker-shared.
        if (! $resolves || count($arguments) !== 1 || $arguments[0]->unpack) {
            return null;
        }

        $abstracts = $scope->getType($arguments[0]->value)->getConstantStrings();

        return $abstracts === [] ? null : array_map(static fn ($abstract): string => $abstract->getValue(), $abstracts);
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
