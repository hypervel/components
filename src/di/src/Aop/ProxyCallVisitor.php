<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use Hypervel\Di\Exceptions\InvalidDefinitionException;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\Coalesce;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Name\Relative;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\MagicConst\Dir as MagicConstDir;
use PhpParser\Node\Scalar\MagicConst\File as MagicConstFile;
use PhpParser\Node\Scalar\MagicConst\Function_ as MagicConstFunction;
use PhpParser\Node\Scalar\MagicConst\Line as MagicConstLine;
use PhpParser\Node\Scalar\MagicConst\Method as MagicConstMethod;
use PhpParser\Node\Scalar\MagicConst\Trait_ as MagicConstTrait;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;

class ProxyCallVisitor extends NodeVisitorAbstract
{
    private const ARGUMENT_FUNCTIONS = [
        'func_get_arg',
        'func_get_args',
        'func_num_args',
    ];

    private const INDIRECT_CALL_FUNCTIONS = [
        'call_user_func',
        'call_user_func_array',
    ];

    private ?ClassLike $targetClassLike = null;

    /**
     * @var array<int, bool>
     */
    private array $classLikeStack = [];

    private ?ClassMethod $activeMethod = null;

    /**
     * @var array<int, FunctionLike>
     */
    private array $nestedFunctionStack = [];

    private string $targetClassName = '';

    private string $targetNamespace = '';

    /**
     * @var array<string, string>
     */
    private array $functionImports = [];

    /**
     * @var array<string, true>
     */
    private array $reservedMethodNames = [];

    private string $helperMethodName = '';

    private string $argumentCountVariable = '';

    private string $variadicArgumentsVariable = '';

    private bool $usesArgumentCount = false;

    private bool $usesArgumentValues = false;

    public function __construct(protected VisitorMetadata $visitorMetadata)
    {
    }

    public function beforeTraverse(array $nodes)
    {
        $this->targetClassName = ltrim($this->visitorMetadata->className, '\\');
        $candidates = $this->findNamedClassLikes($nodes);

        if (count($candidates) > 1) {
            throw new InvalidDefinitionException(
                "Unable to generate an AOP proxy for [{$this->targetClassName}]: its source file "
                . 'contains multiple named classes, interfaces, traits, or enums. Split each named class-like '
                . 'into its own file before targeting it with an aspect.'
            );
        }

        if ($candidates === []) {
            throw new InvalidDefinitionException(
                "Unable to generate an AOP proxy for [{$this->targetClassName}]: "
                . 'the source file does not declare that class-like.'
            );
        }

        $candidate = $candidates[0];

        if (strcasecmp($candidate['class'], $this->targetClassName) !== 0) {
            throw new InvalidDefinitionException(
                "Unable to generate an AOP proxy for [{$this->targetClassName}]: "
                . "the source file declares [{$candidate['class']}] instead."
            );
        }

        $this->targetClassLike = $candidate['node'];
        $this->targetNamespace = $candidate['namespace'];
        $this->functionImports = $this->collectFunctionImports($candidate['statements']);
        $this->visitorMetadata->classLike = $candidate['node']::class;

        foreach ($candidate['node']->getMethods() as $method) {
            $this->reservedMethodNames[strtolower($method->name->toString())] = true;
        }

        return null;
    }

    public function enterNode(Node $node)
    {
        if ($node instanceof ClassLike) {
            $this->classLikeStack[] = $node === $this->targetClassLike;
        }

        if ($this->activeMethod instanceof ClassMethod && $node instanceof FunctionLike && $node !== $this->activeMethod) {
            $this->nestedFunctionStack[] = $node;
        }

        if (
            $node instanceof ClassMethod
            && $this->activeMethod === null
            && end($this->classLikeStack) === true
            && $this->shouldRewrite($node)
        ) {
            if ($node->byRef) {
                throw new InvalidDefinitionException(
                    "Unable to apply an aspect to [{$this->targetClassName}::{$node->name->toString()}]: "
                    . 'methods that return by reference cannot be intercepted safely.'
                );
            }

            $this->beginMethodRewrite($node);
        }

        return null;
    }

    public function leaveNode(Node $node)
    {
        if ($this->activeMethod instanceof ClassMethod) {
            if ($node instanceof MagicConstFile) {
                return new String_($this->visitorMetadata->sourceFilePath, $node->getAttributes());
            }

            if ($node instanceof MagicConstDir) {
                return new String_(dirname($this->visitorMetadata->sourceFilePath), $node->getAttributes());
            }

            if ($node instanceof MagicConstLine) {
                return new Int_($node->getStartLine(), $node->getAttributes());
            }

            if ($node instanceof MagicConstFunction || $node instanceof MagicConstMethod) {
                if ($replacement = $this->rewriteFunctionMagicConstant($node)) {
                    return $replacement;
                }
            }

            if ($this->nestedFunctionStack === [] && $node instanceof FuncCall && $node->name instanceof Name) {
                $this->rejectIndirectArgumentCall($node);

                if ($replacement = $this->rewriteArgumentFunction($node)) {
                    return $replacement;
                }
            }
        }

        if ($node === $this->activeMethod) {
            $rewritten = $this->rewriteMethod($node);
            $this->finishMethodRewrite();

            return $rewritten;
        }

        if ($this->activeMethod instanceof ClassMethod && $node instanceof FunctionLike) {
            array_pop($this->nestedFunctionStack);
        }

        if ($node instanceof ClassLike) {
            $isTarget = array_pop($this->classLikeStack);

            if ($isTarget && ! $node instanceof Interface_) {
                array_unshift($node->stmts, new TraitUse([
                    new FullyQualified(ProxyMarker::class),
                ]));
            }

            return $node;
        }

        return null;
    }

    /**
     * Rewrite an intercepted method into its wrapper and private original-body helper.
     *
     * @return array{ClassMethod, ClassMethod}
     */
    private function rewriteMethod(ClassMethod $node): array
    {
        $helper = clone $node;
        $helper->name = new Identifier($this->helperMethodName);
        $helper->flags = ($node->flags & Class_::MODIFIER_STATIC) | Class_::MODIFIER_PRIVATE;
        $helper->attrGroups = [];
        $helper->setAttribute('comments', []);
        $helper->params = $this->buildHelperParameters($node->params);

        $statements = [];

        if ($this->usesArgumentCount) {
            $statements[] = new Expression(new Assign(
                new Variable($this->argumentCountVariable),
                new FuncCall(new FullyQualified('func_num_args'))
            ));
        }

        $dispatch = new StaticCall(new FullyQualified(ProxyDispatcher::class), 'dispatch', [
            new Arg($this->getTargetMagicConstant()),
            new Arg(new MagicConstFunction),
            new Arg($this->buildArgumentMap($node->params)),
            new Arg($this->buildForwardingClosure($node)),
        ]);

        if ($this->returnsByExpression($node)) {
            $statements[] = new Expression($dispatch);
        } else {
            $statements[] = new Return_($dispatch);
        }

        $node->stmts = $statements;

        return [$node, $helper];
    }

    /**
     * Build the private helper's parameters.
     *
     * @param array<int, Param> $parameters
     * @return array<int, Param>
     */
    private function buildHelperParameters(array $parameters): array
    {
        $helperParameters = [];

        if ($this->usesArgumentCount) {
            $helperParameters[] = new Param(
                new Variable($this->argumentCountVariable),
                type: new Identifier('int')
            );
        }

        if ($this->usesArgumentValues) {
            $helperParameters[] = new Param(
                new Variable($this->variadicArgumentsVariable),
                type: new Identifier('array')
            );
        }

        foreach ($parameters as $parameter) {
            $parameter = clone $parameter;
            $parameter->attrGroups = [];
            $parameter->flags = 0;
            $helperParameters[] = $parameter;
        }

        return $helperParameters;
    }

    /**
     * Build the closure that forwards aspect-adjusted arguments to the helper.
     */
    private function buildForwardingClosure(ClassMethod $method): Closure
    {
        $parameters = [];

        foreach ($method->params as $parameter) {
            $parameter = clone $parameter;
            $parameter->attrGroups = [];
            $parameter->default = null;
            $parameter->flags = 0;
            $parameters[] = $parameter;
        }

        $uses = $this->usesArgumentCount
            ? [new ClosureUse(new Variable($this->argumentCountVariable))]
            : [];

        $helperCall = new StaticCall(new Name('self'), $this->helperMethodName, $this->buildHelperArguments($method));
        $statements = $this->returnsByExpression($method)
            ? [new Expression($helperCall)]
            : [new Return_($helperCall)];

        return new Closure([
            'static' => $method->isStatic(),
            'params' => $parameters,
            'uses' => $uses,
            'stmts' => $statements,
        ]);
    }

    /**
     * Build the arguments passed from the forwarding closure to the helper.
     *
     * @return array<int, Arg>
     */
    private function buildHelperArguments(ClassMethod $method): array
    {
        $arguments = [];

        if ($this->usesArgumentCount) {
            $arguments[] = new Arg(new Variable($this->argumentCountVariable));
        }

        if ($this->usesArgumentValues) {
            $variadic = $this->getVariadicParameter($method->params);

            if ($variadic instanceof Param) {
                $arguments[] = new Arg(new StaticCall(
                    new FullyQualified(ProxyDispatcher::class),
                    'captureVariadicArguments',
                    [
                        new Arg(new Variable($variadic->var->name)),
                        new Arg(new FuncCall(new FullyQualified('max'), [
                            new Arg(new Int_(0)),
                            new Arg(new Node\Expr\BinaryOp\Minus(
                                new Variable($this->argumentCountVariable),
                                new Int_($this->getFixedParameterCount($method->params))
                            )),
                        ])),
                        new Arg(new ConstFetch(new Name($variadic->byRef ? 'true' : 'false'))),
                    ]
                ));
            } else {
                $arguments[] = new Arg(new Array_([], ['kind' => Array_::KIND_SHORT]));
            }
        }

        foreach ($method->params as $parameter) {
            $arguments[] = new Arg(
                new Variable($parameter->var->name),
                unpack: $parameter->variadic
            );
        }

        return $arguments;
    }

    /**
     * Build the join-point argument map.
     *
     * @param array<int, Param> $parameters
     */
    private function buildArgumentMap(array $parameters): Array_
    {
        $order = [];
        $keys = [];
        $variadic = '';

        foreach ($parameters as $parameter) {
            $name = $parameter->var->name;
            $order[] = new ArrayItem(new String_($name));
            $keys[] = new ArrayItem(
                new Variable($name),
                new String_($name),
                $parameter->byRef
            );

            if ($parameter->variadic) {
                $variadic = $name;
            }
        }

        return new Array_([
            new ArrayItem(new Array_($order, ['kind' => Array_::KIND_SHORT]), new String_('order')),
            new ArrayItem(new Array_($keys, ['kind' => Array_::KIND_SHORT]), new String_('keys')),
            new ArrayItem(new String_($variadic), new String_('variadic')),
        ], ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Rewrite one direct argument-introspection call.
     */
    private function rewriteArgumentFunction(FuncCall $call): ?Node\Expr
    {
        $resolution = $this->resolveArgumentFunction($call->name);

        if ($resolution === null || $call->isFirstClassCallable()) {
            return null;
        }

        $arguments = $call->getArgs();

        if (array_any($arguments, static fn (Arg $argument): bool => $argument->unpack)) {
            throw new InvalidDefinitionException(
                "Unable to apply an aspect to [{$this->targetClassName}::{$this->activeMethod?->name->toString()}]: "
                . "calls to {$resolution['function']}() cannot use argument unpacking. "
                . "Call {$resolution['function']}() without argument unpacking."
            );
        }

        if (! $this->hasValidArgumentFunctionArity($resolution['function'], $arguments)) {
            return null;
        }

        $replacement = $this->buildArgumentFunctionReplacement($resolution['function'], $call);

        if ($resolution['fallback'] === null) {
            return $replacement;
        }

        return new Ternary(
            new FuncCall(new FullyQualified('function_exists'), [
                new Arg(new String_($resolution['fallback'])),
            ]),
            new FuncCall(
                new FullyQualified($resolution['fallback']),
                array_map(static fn (Arg $argument) => clone $argument, $call->args)
            ),
            $replacement,
            $call->getAttributes()
        );
    }

    /**
     * Preserve method and closure descriptors from the original source scope.
     */
    private function rewriteFunctionMagicConstant(MagicConstFunction|MagicConstMethod $constant): ?String_
    {
        if (end($this->classLikeStack) !== true) {
            return null;
        }

        if ($this->nestedFunctionStack === []) {
            $value = $constant instanceof MagicConstFunction
                ? $this->activeMethod?->name->toString()
                : $this->targetClassName . '::' . $this->activeMethod?->name->toString();

            return new String_($value, $constant->getAttributes());
        }

        $descriptor = $this->buildClosureDescriptor();

        return $descriptor === null
            ? null
            : new String_($descriptor, $constant->getAttributes());
    }

    /**
     * Build PHP's closure descriptor using original lexical names and lines.
     */
    private function buildClosureDescriptor(): ?string
    {
        $base = $this->targetClassName . '::' . $this->activeMethod?->name->toString() . '()';
        $closures = [];

        foreach ($this->nestedFunctionStack as $functionLike) {
            if ($functionLike instanceof ClassMethod) {
                return null;
            }

            if ($functionLike instanceof Function_) {
                $base = ($this->targetNamespace === '' ? '' : $this->targetNamespace . '\\')
                    . $functionLike->name->toString()
                    . '()';
                $closures = [];

                continue;
            }

            if ($functionLike instanceof Closure || $functionLike instanceof ArrowFunction) {
                $closures[] = $functionLike;

                continue;
            }

            return null;
        }

        if ($closures === []) {
            return null;
        }

        foreach ($closures as $closure) {
            $base = $this->formatClosureDescriptor($base, $closure->getStartLine());
        }

        return $base;
    }

    /**
     * Format the PHP 8.4 closure descriptor shape.
     */
    private function formatClosureDescriptor(string $parent, int $line): string
    {
        return "{closure:{$parent}:{$line}}";
    }

    /**
     * Resolve whether a call can invoke a built-in argument-introspection function.
     *
     * @return null|array{function: string, fallback: ?string}
     */
    private function resolveArgumentFunction(Name $name): ?array
    {
        if ($name instanceof Relative) {
            return null;
        }

        if ($name instanceof FullyQualified) {
            $function = strtolower($name->toString());

            return in_array($function, self::ARGUMENT_FUNCTIONS, true)
                ? ['function' => $function, 'fallback' => null]
                : null;
        }

        if (! $name->isUnqualified()) {
            return null;
        }

        $localName = strtolower($name->toString());

        if (isset($this->functionImports[$localName])) {
            $function = strtolower($this->functionImports[$localName]);

            return in_array($function, self::ARGUMENT_FUNCTIONS, true)
                ? ['function' => $function, 'fallback' => null]
                : null;
        }

        if (! in_array($localName, self::ARGUMENT_FUNCTIONS, true)) {
            return null;
        }

        return [
            'function' => $localName,
            'fallback' => $this->targetNamespace === ''
                ? null
                : $this->targetNamespace . '\\' . $localName,
        ];
    }

    /**
     * Build the preserved implementation of one argument-introspection function.
     */
    private function buildArgumentFunctionReplacement(string $function, FuncCall $call): Node\Expr
    {
        $this->usesArgumentCount = true;

        if ($function === 'func_num_args') {
            return new Variable($this->argumentCountVariable, $call->getAttributes());
        }

        $this->usesArgumentValues = true;
        $arguments = [
            new Arg(new Variable($this->argumentCountVariable)),
            new Arg($this->buildCurrentFixedArguments()),
            new Arg(new Variable($this->variadicArgumentsVariable)),
        ];

        if ($function === 'func_get_arg') {
            $arguments[] = clone $call->args[0];
        }

        return new StaticCall(
            new FullyQualified(ProxyDispatcher::class),
            $function === 'func_get_arg' ? 'resolveArgument' : 'resolveArguments',
            $arguments,
            $call->getAttributes()
        );
    }

    /**
     * Build the helper's current fixed-parameter values.
     */
    private function buildCurrentFixedArguments(): Array_
    {
        $arguments = [];

        foreach ($this->activeMethod->params as $parameter) {
            if ($parameter->variadic) {
                break;
            }

            $arguments[] = new ArrayItem(new Coalesce(
                new Variable($parameter->var->name),
                new ConstFetch(new Name('null'))
            ));
        }

        return new Array_($arguments, ['kind' => Array_::KIND_SHORT]);
    }

    /**
     * Reject indirect argument-introspection calls whose caller-frame behavior cannot be preserved.
     */
    private function rejectIndirectArgumentCall(FuncCall $call): void
    {
        if ($call->isFirstClassCallable() || ! $this->resolvesToGlobalFunction($call->name, self::INDIRECT_CALL_FUNCTIONS)) {
            return;
        }

        $callback = $call->args[0]->value ?? null;

        if (! $callback instanceof String_) {
            return;
        }

        $function = strtolower($callback->value);

        if (! in_array($function, self::ARGUMENT_FUNCTIONS, true)) {
            return;
        }

        throw new InvalidDefinitionException(
            "Unable to apply an aspect to [{$this->targetClassName}::{$this->activeMethod?->name->toString()}]: "
            . "indirect calls to {$function}() cannot preserve the original method frame. Call {$function}() directly."
        );
    }

    /**
     * Determine whether a function name can resolve to one of the given global functions.
     *
     * Bare calls in a namespace may fall back to a global function at runtime. For
     * indirect argument-introspection calls, rejecting that ambiguous source shape
     * is preferable to silently exposing the generated helper's call frame.
     *
     * @param array<int, string> $functions
     */
    private function resolvesToGlobalFunction(Name $name, array $functions): bool
    {
        if ($name instanceof Relative) {
            return false;
        }

        if ($name instanceof FullyQualified) {
            return in_array(strtolower($name->toString()), $functions, true);
        }

        if (! $name->isUnqualified()) {
            return false;
        }

        $localName = strtolower($name->toString());

        if (isset($this->functionImports[$localName])) {
            return in_array(strtolower($this->functionImports[$localName]), $functions, true);
        }

        return in_array($localName, $functions, true);
    }

    /**
     * Determine whether a direct argument-introspection call has its native arity.
     *
     * @param array<int, Arg> $arguments
     */
    private function hasValidArgumentFunctionArity(string $function, array $arguments): bool
    {
        return count($arguments) === ($function === 'func_get_arg' ? 1 : 0);
    }

    /**
     * Begin tracking one target method rewrite.
     */
    private function beginMethodRewrite(ClassMethod $method): void
    {
        $this->activeMethod = $method;
        $this->nestedFunctionStack = [];
        $hash = substr(hash('sha256', $this->targetClassName . '::' . $method->name->toString()), 0, 12);
        $this->helperMethodName = $this->reserveMethodName("__hypervelAopOriginal_{$hash}");

        $usedVariables = [];
        $finder = new NodeFinder;

        foreach ($finder->findInstanceOf($method, Variable::class) as $variable) {
            if (is_string($variable->name)) {
                $usedVariables[$variable->name] = true;
            }
        }

        $this->argumentCountVariable = $this->uniqueVariableName("__hypervelAopCount_{$hash}", $usedVariables);
        $usedVariables[$this->argumentCountVariable] = true;
        $this->variadicArgumentsVariable = $this->uniqueVariableName("__hypervelAopVariadic_{$hash}", $usedVariables);
        $this->usesArgumentCount = false;
        $this->usesArgumentValues = false;
    }

    /**
     * Finish tracking the current method rewrite.
     */
    private function finishMethodRewrite(): void
    {
        $this->activeMethod = null;
        $this->nestedFunctionStack = [];
        $this->helperMethodName = '';
        $this->argumentCountVariable = '';
        $this->variadicArgumentsVariable = '';
        $this->usesArgumentCount = false;
        $this->usesArgumentValues = false;
    }

    /**
     * Reserve a deterministic generated method name without colliding with source methods.
     */
    private function reserveMethodName(string $base): string
    {
        $name = $base;
        $suffix = 0;

        while (isset($this->reservedMethodNames[strtolower($name)])) {
            $name = $base . '_' . ++$suffix;
        }

        $this->reservedMethodNames[strtolower($name)] = true;

        return $name;
    }

    /**
     * Build a generated variable name that does not change source-local behavior.
     *
     * @param array<string, true> $usedVariables
     */
    private function uniqueVariableName(string $base, array $usedVariables): string
    {
        $name = $base;
        $suffix = 0;

        while (isset($usedVariables[$name])) {
            $name = $base . '_' . ++$suffix;
        }

        return $name;
    }

    /**
     * Find every named class-like and its namespace.
     *
     * @return array<int, array{class: string, namespace: string, node: ClassLike, statements: array<int, Node\Stmt>}>
     */
    private function findNamedClassLikes(array $nodes): array
    {
        $finder = new NodeFinder;
        $candidates = [];

        foreach ($nodes as $node) {
            $namespace = $node instanceof Namespace_ && $node->name !== null
                ? $node->name->toString()
                : '';
            $statements = $node instanceof Namespace_ ? $node->stmts : [$node];

            foreach ($finder->findInstanceOf($statements, ClassLike::class) as $classLike) {
                if ($classLike->name === null) {
                    continue;
                }

                $class = $classLike->name->toString();
                $candidates[] = [
                    'class' => $namespace === '' ? $class : $namespace . '\\' . $class,
                    'namespace' => $namespace,
                    'node' => $classLike,
                    'statements' => $node instanceof Namespace_ ? $node->stmts : $nodes,
                ];
            }
        }

        return $candidates;
    }

    /**
     * Collect function import aliases for the target namespace.
     *
     * @param array<int, Node\Stmt> $statements
     * @return array<string, string>
     */
    private function collectFunctionImports(array $statements): array
    {
        $imports = [];

        foreach ($statements as $statement) {
            if (! $statement instanceof Use_ && ! $statement instanceof GroupUse) {
                continue;
            }

            foreach ($statement->uses as $use) {
                $type = $use->type !== Use_::TYPE_UNKNOWN ? $use->type : $statement->type;

                if ($type !== Use_::TYPE_FUNCTION) {
                    continue;
                }

                $name = $statement instanceof GroupUse
                    ? $statement->prefix->toString() . '\\' . $use->name->toString()
                    : $use->name->toString();
                $alias = $use->alias?->toString() ?? $use->name->getLast();
                $imports[strtolower($alias)] = ltrim($name, '\\');
            }
        }

        return $imports;
    }

    /**
     * Get the class or trait identity used by the runtime aspect registry.
     */
    private function getTargetMagicConstant(): Node\Scalar\MagicConst
    {
        return $this->targetClassLike instanceof Trait_
            ? new MagicConstTrait
            : new Node\Scalar\MagicConst\Class_;
    }

    /**
     * Determine whether the method's return contract forbids a return statement.
     */
    private function returnsByExpression(ClassMethod $method): bool
    {
        $returnType = $method->getReturnType();

        return $returnType instanceof Identifier
            && in_array(strtolower($returnType->name), ['never', 'void'], true);
    }

    /**
     * Get the variadic parameter, if present.
     *
     * @param array<int, Param> $parameters
     */
    private function getVariadicParameter(array $parameters): ?Param
    {
        foreach ($parameters as $parameter) {
            if ($parameter->variadic) {
                return $parameter;
            }
        }

        return null;
    }

    /**
     * Count the fixed parameters before a possible variadic parameter.
     *
     * @param array<int, Param> $parameters
     */
    private function getFixedParameterCount(array $parameters): int
    {
        return count($parameters) - ($this->getVariadicParameter($parameters) instanceof Param ? 1 : 0);
    }

    /**
     * Determine if the method should be rewritten.
     */
    private function shouldRewrite(ClassMethod $node): bool
    {
        if ($this->visitorMetadata->classLike === Interface_::class || $node->isAbstract()) {
            return false;
        }

        return Aspect::parse($this->visitorMetadata->className)
            ->shouldRewrite($node->name->toString());
    }
}
