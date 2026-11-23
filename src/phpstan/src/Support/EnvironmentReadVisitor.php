<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeVisitorAbstract;

use function array_key_last;
use function array_pop;

/**
 * Collect the environment variables the traversed files read for EnvironmentVariableScanner.
 *
 * It tracks the class and function enclosing each read, because a read whose name is computed
 * is accounted for only inside the readers themselves and the modeled framework methods.
 *
 * @internal
 */
class EnvironmentReadVisitor extends NodeVisitorAbstract
{
    /**
     * The constant variable names read.
     *
     * @var array<string, true>
     */
    public array $names = [];

    /**
     * Whether a read's name is computed outside the readers and modeled methods.
     */
    public bool $dynamic = false;

    /**
     * The enclosing class-likes, null for an anonymous class.
     *
     * @var list<null|string>
     */
    private array $classes = [];

    /**
     * The enclosing named functions and methods; closures run in theirs.
     *
     * @var list<null|string>
     */
    private array $functions = [];

    /**
     * Create a new visitor instance.
     */
    public function __construct(
        private EnvironmentVariableScanner $scanner,
    ) {
    }

    /**
     * Record the read a node makes, after entering the class or function it declares.
     */
    public function enterNode(Node $node): null
    {
        // The name resolver leaves an anonymous class's name unset.
        if ($node instanceof ClassLike) {
            $this->classes[] = isset($node->namespacedName) ? $node->namespacedName->toString() : null;
        } elseif ($node instanceof ClassMethod) {
            $this->functions[] = $this->classes[array_key_last($this->classes)] . '::' . $node->name->toString();
        } elseif ($node instanceof Function_) {
            $this->functions[] = isset($node->namespacedName) ? $node->namespacedName->toString() : null;
        }

        $class = $this->classes === [] ? null : $this->classes[array_key_last($this->classes)];
        $name = $this->scanner->readName($node, $class);

        if ($name === null) {
            return null;
        }

        if ($name !== '') {
            $this->names[$name] = true;
        } elseif (! $this->scanner->readsComputedNames(
            $class,
            $this->functions === [] ? null : $this->functions[array_key_last($this->functions)],
        )) {
            $this->dynamic = true;
        }

        return null;
    }

    /**
     * Leave the class or function a node declares.
     */
    public function leaveNode(Node $node): null
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classes);
        } elseif ($node instanceof ClassMethod || $node instanceof Function_) {
            array_pop($this->functions);
        }

        return null;
    }
}
