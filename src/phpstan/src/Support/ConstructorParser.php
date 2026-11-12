<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;

use function in_array;
use function is_string;
use function sprintf;
use function strlen;
use function strrpos;
use function strtolower;

/**
 * Reads the constructor a class's instances run from its source.
 */
#[AutowiredService]
class ConstructorParser
{
    /**
     * What each class's constructor writes: the properties it assigns and the methods it calls on the instance,
     * or true when it assigns a property or calls a method it does not name.
     *
     * @var array<string, array{properties: array<string, true>, methods: array<string, true>}|true>
     */
    private array $writes = [];

    /**
     * Create a new constructor parser instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
    ) {
    }

    /**
     * Find the constructor the class's instances run (its own, an inherited one or a trait's), with the class
     * declaring it, which `parent::` is relative to. A trait's constructor is declared by the class using it.
     * Null when there is no constructor in source.
     *
     * @return null|array{ClassMethod, ClassReflection}
     */
    public function findConstructor(ClassReflection $classReflection): ?array
    {
        $native = $classReflection->getNativeReflection();

        if (! $native->hasMethod('__construct')) {
            return null;
        }

        $constructor = $native->getMethod('__construct');
        $fileName = $constructor->getFileName();

        if ($fileName === false) {
            return null;
        }

        // A trait method imported as the constructor keeps its own name, so the node is found by its position.
        $method = $constructor->getBetterReflection();
        $source = $method->getLocatedSource()->getSource();
        $node = (new NodeFinder)->findFirst(
            $this->parser->parseFile($fileName),
            static fn (Node $node): bool => $node instanceof ClassMethod
                && $node->getStartLine() === $method->getStartLine()
                && self::column($source, $node->getStartFilePos()) === $method->getStartColumn(),
        );

        if (! $node instanceof ClassMethod) {
            throw new LogicException(sprintf('Constructor of [%s] was not found in [%s].', $classReflection->getName(), $fileName));
        }

        $declaringClass = $constructor->getDeclaringClass()->getName();

        return [
            $node,
            $classReflection->getAncestorWithClassName($declaringClass)
                ?? throw new LogicException(sprintf('Constructor declaring class [%s] is not an ancestor of [%s].', $declaringClass, $classReflection->getName())),
        ];
    }

    /**
     * Determine whether the constructor the class's instances run may change the property, following
     * `parent::__construct()`: an assignment to it or through an array dimension of it, a promoted parameter,
     * or a call on the instance to one of the given setters.
     *
     * The bodies of the other methods it calls are not read.
     *
     * @param list<string> $setters
     */
    public function writesProperty(ClassReflection $classReflection, string $property, array $setters = []): bool
    {
        $writes = $this->writes($classReflection);

        if ($writes === true || isset($writes['properties'][$property])) {
            return true;
        }

        foreach ($setters as $setter) {
            if (isset($writes['methods'][strtolower($setter)])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get what the constructor the class's instances run writes.
     *
     * @return array{properties: array<string, true>, methods: array<string, true>}|true
     */
    private function writes(ClassReflection $classReflection): array|true
    {
        return $this->writes[$classReflection->getName()] ??= $this->resolveWrites($classReflection);
    }

    /**
     * Resolve what the constructor the class's instances run writes.
     *
     * @return array{properties: array<string, true>, methods: array<string, true>}|true
     */
    private function resolveWrites(ClassReflection $classReflection): array|true
    {
        $writes = ['properties' => [], 'methods' => []];
        $constructor = $this->findConstructor($classReflection);

        if ($constructor === null) {
            return $writes;
        }

        [$node, $declaringClass] = $constructor;

        foreach ($node->params as $param) {
            if ($param->isPromoted() && $param->var instanceof Variable && is_string($param->var->name)) {
                $writes['properties'][$param->var->name] = true;
            }
        }

        /** @var list<Assign|AssignOp|AssignRef|MethodCall|NullsafeMethodCall|StaticCall> $nodes */
        $nodes = (new NodeFinder)->find(
            $node->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Assign || $node instanceof AssignOp || $node instanceof AssignRef
                || $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall,
        );

        foreach ($nodes as $expression) {
            if ($expression instanceof StaticCall) {
                if (! $expression->class instanceof Name || ! in_array($expression->class->toLowerString(), ['self', 'static', 'parent'], true)) {
                    continue;
                }

                if (! $expression->name instanceof Identifier) {
                    return true;
                }

                if ($expression->class->toLowerString() !== 'parent' || $expression->name->toLowerString() !== '__construct') {
                    $writes['methods'][$expression->name->toLowerString()] = true;

                    continue;
                }

                $parent = $declaringClass->getParentClass();

                if ($parent === null) {
                    continue;
                }

                $parentWrites = $this->writes($parent);

                if ($parentWrites === true) {
                    return true;
                }

                $writes['properties'] += $parentWrites['properties'];
                $writes['methods'] += $parentWrites['methods'];

                continue;
            }

            if ($expression instanceof MethodCall || $expression instanceof NullsafeMethodCall) {
                // A call chain continues on the instance through setters returning it.
                $target = $expression->var;

                while ($target instanceof MethodCall || $target instanceof NullsafeMethodCall) {
                    $target = $target->var;
                }

                if (! $target instanceof Variable || $target->name !== 'this') {
                    continue;
                }

                if (! $expression->name instanceof Identifier) {
                    return true;
                }

                $writes['methods'][$expression->name->toLowerString()] = true;

                continue;
            }

            $target = $expression->var;

            while ($target instanceof ArrayDimFetch) {
                $target = $target->var;
            }

            if (! $target instanceof PropertyFetch || ! $target->var instanceof Variable || $target->var->name !== 'this') {
                continue;
            }

            if (! $target->name instanceof Identifier) {
                return true;
            }

            $writes['properties'][$target->name->toString()] = true;
        }

        return $writes;
    }

    /**
     * Get the column of a source position, as BetterReflection counts it for a method's start column.
     */
    private static function column(string $source, int $position): int
    {
        $lineStart = strrpos($source, "\n", $position - strlen($source));

        return $lineStart === false ? $position + 1 : $position - $lineStart;
    }
}
