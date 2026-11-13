<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules;

use Hypervel\Container\Attributes\BindWhen;
use Hypervel\Container\Container;
use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\PHPStan\Concerns\HasContainer;
use Hypervel\PHPStan\Rules\Coroutine\CoroutineRuleHelper;
use Hypervel\PHPStan\Rules\Coroutine\WorkerStateHelper;
use Hypervel\PHPStan\Support\ConstructorParser;
use Hypervel\PHPStan\Support\FacadeHelper;
use Hypervel\PHPStan\Support\RuntimeInputs;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Node\InClassNode;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function array_any;
use function is_array;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Reports a #[BindWhen] condition whose code, including its nested callbacks, contains a read of the
 * current request or user.
 *
 * A matched condition becomes a binding for the worker's lifetime, so the first request it matches
 * selects the implementation for every later one. Conditions are read from source, never executed, so
 * whether a nested callback actually runs isn't known.
 *
 * @implements Rule<InClassNode>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%checkBindWhenConditions%')]
class RequestDependentBindingRule implements Rule
{
    use HasContainer;

    /**
     * The lowercase auth methods that read the current user.
     */
    private const array AUTH_READS = ['user' => true, 'check' => true, 'guest' => true, 'id' => true, 'hasuser' => true];

    /**
     * The lowercase RequestContext methods that read the current request or its values.
     */
    private const array REQUEST_CONTEXT_READS = ['get' => true, 'getornull' => true, 'has' => true, 'getvalue' => true, 'getorsetvalue' => true];

    /**
     * Create a new request-dependent binding rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private RuntimeInputs $runtimeInputs,
        private WorkerStateHelper $workerStateHelper,
        private ConstructorParser $constructorParser,
        private CoroutineRuleHelper $coroutineRuleHelper,
        private ScopeFactory $scopeFactory,
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * Report each #[BindWhen] condition on the class that reads request or user state.
     *
     * @param InClassNode $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $errors = [];

        foreach ($node->getOriginalNode()->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strtolower($scope->resolveName($attribute->name)) !== strtolower(BindWhen::class)) {
                    continue;
                }

                $condition = $this->getCondition($attribute->args);

                if ($condition === null) {
                    continue;
                }

                // The booted aliases decide which abstracts resolve the request.
                $this->runtimeInputs->track($scope);

                $line = $this->findRequestRead($condition, $scope);

                if ($line === null) {
                    continue;
                }

                $errors[] = RuleErrorBuilder::message(sprintf('The #[BindWhen] condition on %s contains a read of the current request or user, but a matched condition binds for the worker\'s lifetime.', $node->getClassReflection()->getName()))
                    ->identifier('hypervel.requestDependentBinding')
                    ->line($line)
                    ->tip('Conditions must depend only on state set while the application boots. Register a scoped() binding to choose an implementation per request.')
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * Get the condition argument of a #[BindWhen] attribute.
     *
     * @param array<Arg> $args
     */
    private function getCondition(array $args): ?Expr
    {
        foreach ($args as $index => $argument) {
            if ($argument->name === null ? $index === 1 : $argument->name->toString() === 'condition') {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * Find the line of the condition's first read of request or user state. A first-class callable's
     * body is in its own declaration, so the attribute's line is reported for it.
     *
     * @param DependencyTracker&Scope $scope
     */
    private function findRequestRead(Expr $condition, Scope $scope): ?int
    {
        if ($condition instanceof Closure || $condition instanceof ArrowFunction) {
            $nodes = $condition instanceof ArrowFunction ? [$condition->expr] : $condition->stmts;

            return $this->findRead($nodes, $this->getContainerVariable($condition->params), $scope)?->getStartLine();
        }

        // A named method can itself be the read, such as Auth::check(...).
        if ($condition instanceof StaticCall && $condition->isFirstClassCallable() && $condition->name instanceof Identifier) {
            $class = $this->getStaticClass($condition, $scope);

            if ($class !== null && $this->isStaticRead($class, $condition->name->toString())) {
                return $condition->getStartLine();
            }
        }

        $declaration = match (true) {
            $condition instanceof FuncCall && $condition->isFirstClassCallable() => $this->findFunction($condition, $scope),
            $condition instanceof StaticCall && $condition->isFirstClassCallable() => $this->findStaticMethod($condition, $scope),
            default => null,
        };

        if ($declaration === null) {
            return null;
        }

        [$function, $file, $class] = $declaration;

        // Function bodies are not declarations, so the file the condition was read from is tracked.
        if ($file !== $scope->getFile()) {
            $scope->trackFileDependency($file);
        }

        // Unqualified function names resolve in the declaration's namespace, not the attribute's, and
        // self:: in a method to its declaring class.
        $namespace = $this->getNamespace($function, $file);
        $declarationScope = $this->scopeFactory->create(ScopeContext::create($file));

        $declarationScope = $namespace === null ? $declarationScope : $declarationScope->enterNamespace($namespace);
        $declarationScope = $class === null ? $declarationScope : $declarationScope->enterClass($class);

        return $this->findRead($function->stmts ?? [], $this->getContainerVariable($function->params), $declarationScope) === null
            ? null
            : $condition->getStartLine();
    }

    /**
     * Get the variable of the condition's first parameter, which receives the container resolving the binding.
     *
     * @param array<Param> $params
     */
    private function getContainerVariable(array $params): ?string
    {
        $variable = ($params[0] ?? null)?->var;

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : null;
    }

    /**
     * Find the first read of request or user state in a function body. The variable holding the resolving
     * container loses that evidence when the body assigns it.
     *
     * @param array<Node> $nodes
     */
    private function findRead(array $nodes, ?string $container, Scope $scope): ?Node
    {
        if ($container !== null && $this->coroutineRuleHelper->find($nodes, fn (Node $node): bool => $this->coroutineRuleHelper->assigns($node, $container)) !== []) {
            $container = null;
        }

        return $this->findReadIn($nodes, $container, $scope);
    }

    /**
     * Find the first read among the nodes and their children, in source order. Nested closures and arrow
     * functions are searched as bodies of their own, since predicates often run callbacks, but named
     * functions and classes are declarations that don't run during the condition.
     *
     * @param array<mixed> $nodes
     */
    private function findReadIn(array $nodes, ?string $container, Scope $scope): ?Node
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Node || $node instanceof Function_ || $node instanceof ClassLike) {
                continue;
            }

            if ($node instanceof Closure || $node instanceof ArrowFunction) {
                $read = $this->findRead(
                    $node instanceof ArrowFunction ? [$node->expr] : $node->stmts,
                    $this->getCapturedContainer($node, $container),
                    $scope,
                );

                if ($read !== null) {
                    return $read;
                }

                continue;
            }

            if ($this->readsRequest($node, $container, $scope)) {
                return $node;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $children = $node->{$subNodeName};
                $read = $this->findReadIn(is_array($children) ? $children : [$children], $container, $scope);

                if ($read !== null) {
                    return $read;
                }
            }
        }

        return null;
    }

    /**
     * Get the container variable inside a nested closure or arrow function: an arrow function captures it
     * unless one of its parameters has the same name, and a closure only through use.
     */
    private function getCapturedContainer(Closure|ArrowFunction $function, ?string $container): ?string
    {
        if ($container === null
            || array_any($function->params, static fn (Param $param): bool => $param->var instanceof Variable && $param->var->name === $container)
        ) {
            return null;
        }

        return $function instanceof ArrowFunction || array_any($function->uses, static fn (ClosureUse $use): bool => $use->var->name === $container)
            ? $container
            : null;
    }

    /**
     * Determine whether the node reads the current request or user: request(), the Request facade,
     * RequestContext, an auth read, or resolving the request from the container.
     */
    private function readsRequest(Node $node, ?string $container, Scope $scope): bool
    {
        if (! $node instanceof CallLike || $node->isFirstClassCallable()) {
            return false;
        }

        if ($node instanceof FuncCall
            && $node->name instanceof Name
            && strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '') === 'request'
        ) {
            return true;
        }

        if ($node instanceof StaticCall && $node->name instanceof Identifier) {
            $class = $this->getStaticClass($node, $scope);

            if ($class !== null && $this->isStaticRead($class, $node->name->toString())) {
                return true;
            }
        }

        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && isset(self::AUTH_READS[$node->name->toLowerString()])
            && $this->isAuthSource($node->var, $scope)
        ) {
            return true;
        }

        $abstracts = $node instanceof MethodCall && $container !== null && $node->var instanceof Variable && $node->var->name === $container
            ? $this->workerStateHelper->getContainerResolvedAbstracts($node, $scope)
            : $this->workerStateHelper->getApplicationResolvedAbstracts($node, $scope);

        if ($abstracts === null) {
            return false;
        }

        /** @var Container $application */
        $application = $this->getContainer();

        return array_any($abstracts, static fn (string $abstract): bool => $application->getAlias($abstract) === 'request');
    }

    /**
     * Determine whether the expression gives the auth manager or a guard: auth(), or a guard taken from
     * it or the Auth facade. An Auth facade subclass's own guard() overrides it, so its result is unknown.
     */
    private function isAuthSource(Expr $expr, Scope $scope): bool
    {
        if ($expr instanceof StaticCall) {
            $class = $this->getStaticClass($expr, $scope);

            return $expr->name instanceof Identifier
                && $expr->name->toLowerString() === 'guard'
                && $class !== null
                && $class->is(Auth::class)
                && ! $class->hasNativeMethod('guard')
                && FacadeHelper::forwardsToRoot($class);
        }

        return match (true) {
            $expr instanceof FuncCall => $expr->name instanceof Name
                && strtolower($this->reflectionProvider->resolveFunctionName($expr->name, $scope) ?? '') === 'auth',
            $expr instanceof MethodCall => $expr->name instanceof Identifier
                && $expr->name->toLowerString() === 'guard'
                && $this->isAuthSource($expr->var, $scope),
            default => false,
        };
    }

    /**
     * Determine whether calling the method on the class reads request or user state: a RequestContext
     * read, a Request facade call the current request runs, or an Auth facade read. A subclass's own
     * declaration of the method overrides it, and a facade's own __callStatic() can forward the call
     * anywhere, so either makes the call unknown.
     */
    private function isStaticRead(ClassReflection $class, string $method): bool
    {
        $lowercase = strtolower($method);

        if ($class->is(RequestContext::class)) {
            return isset(self::REQUEST_CONTEXT_READS[$lowercase])
                && $class->getNativeMethod($method)->getDeclaringClass()->getName() === RequestContext::class;
        }

        if ($class->is(RequestFacade::class)) {
            $request = $this->reflectionProvider->getClass(Request::class);

            // The facade's own methods manage the facade, and static request methods such as hasMacro()
            // or create() don't read the current request.
            return ! $class->hasNativeMethod($method)
                && FacadeHelper::forwardsToRoot($class)
                && ! ($request->hasNativeMethod($method) && $request->getNativeMethod($method)->isStatic());
        }

        return $class->is(Auth::class)
            && isset(self::AUTH_READS[$lowercase])
            && ! $class->hasNativeMethod($method)
            && FacadeHelper::forwardsToRoot($class);
    }

    /**
     * Get the named class the static call is made on. The scope resolves self and parent; static is late-bound,
     * so its class is unknown.
     */
    private function getStaticClass(StaticCall $call, Scope $scope): ?ClassReflection
    {
        if (! $call->class instanceof Name || $call->class->toLowerString() === 'static') {
            return null;
        }

        $class = $scope->resolveName($call->class);

        return $this->reflectionProvider->hasClass($class) ? $this->reflectionProvider->getClass($class) : null;
    }

    /**
     * Get the namespace the file declares the function or method in: that of the last namespace
     * statement before it.
     */
    private function getNamespace(Node $declaration, string $file): ?string
    {
        $namespace = null;

        foreach ($this->parser->parseFile($file) as $statement) {
            if ($statement instanceof Namespace_ && $statement->getStartFilePos() < $declaration->getStartFilePos()) {
                $namespace = $statement->name?->toString();
            }
        }

        return $namespace;
    }

    /**
     * Find the declaration of the function a first-class callable names, with its file.
     *
     * @return null|array{Function_, string, null}
     */
    private function findFunction(FuncCall $callable, Scope $scope): ?array
    {
        if (! $callable->name instanceof Name || ! $this->reflectionProvider->hasFunction($callable->name, $scope)) {
            return null;
        }

        $function = $this->reflectionProvider->getFunction($callable->name, $scope);
        $file = $function->getFileName();

        if ($file === null) {
            return null;
        }

        $declaration = (new NodeFinder)->findFirst(
            $this->parser->parseFile($file),
            static fn (Node $node): bool => $node instanceof Function_
                && strtolower($node->namespacedName?->toString() ?? '') === strtolower($function->getName()),
        );

        return $declaration instanceof Function_ ? [$declaration, $file, null] : null;
    }

    /**
     * Find the declaration of the static method a first-class callable names, with its file and the
     * class declaring it.
     *
     * @return null|array{ClassMethod, string, ClassReflection}
     */
    private function findStaticMethod(StaticCall $callable, Scope $scope): ?array
    {
        $class = $this->getStaticClass($callable, $scope);

        if ($class === null || ! $callable->name instanceof Identifier) {
            return null;
        }

        $declaration = $this->constructorParser->findMethod($class, $callable->name->toString());

        return $declaration === null
            ? null
            : [...$declaration, $class->getNativeMethod($callable->name->toString())->getDeclaringClass()];
    }
}
