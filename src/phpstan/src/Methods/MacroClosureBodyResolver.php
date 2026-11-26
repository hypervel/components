<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Methods;

use Closure;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\DeclarationDependencyTracker;
use PHPStan\Analyser\ExpressionResultStorage;
use PHPStan\Analyser\NodeScopeResolver;
use PHPStan\Analyser\ScopeContext;
use PHPStan\Analyser\ScopeFactory;
use PHPStan\Dependency\Dependencies;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Container;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ClosureType;
use ReflectionFunction;
use Throwable;

use function array_key_exists;
use function array_keys;
use function count;
use function is_file;
use function str_starts_with;

/**
 * Infer a macro closure's type from its body, as the class the macro runs on.
 *
 * A closure without a declared return type would otherwise return mixed. The body is analysed
 * once per macro, and the classes, functions and files it reads become dependencies of the class,
 * since they can change without the boot inputs that registered the macro.
 */
#[AutowiredService]
class MacroClosureBodyResolver
{
    /**
     * The inferred closure type of each class's macro, or null when it cannot be inferred.
     *
     * @var array<string, null|ClosureType>
     */
    private array $types = [];

    /**
     * The class names and files read by the bodies of the macros each class reaches, by class name.
     *
     * A body calling another class's macro reads what that macro's body reads, which PHPStan's class
     * dependencies do not carry, so a body also depends on what is recorded for the classes it reads.
     *
     * @var array<string, array{array<string, true>, array<string, true>}>
     */
    private array $classDependencies = [];

    /**
     * The macros whose bodies are being analysed, so a macro calling itself falls back to its signature.
     *
     * @var array<string, true>
     */
    private array $resolving = [];

    /**
     * Create a macro closure body resolver.
     */
    public function __construct(
        private ScopeFactory $scopeFactory,
        private Container $container,
        private DeclarationDependencyTracker $declarationDependencyTracker,
        #[AutowiredParameter(ref: '@cachedRichParser')]
        private Parser $parser,
    ) {
    }

    /**
     * Resolve the type of the class's macro closure from its body, for a call on the receiver.
     */
    public function resolve(Closure $closure, ClassReflection $receiver, ClassReflection $classReflection, string $methodName): ?ClosureType
    {
        $key = $classReflection->getCacheKey() . '::' . $methodName;

        if (! array_key_exists($key, $this->types)) {
            if (isset($this->resolving[$key])) {
                return null;
            }

            $this->resolving[$key] = true;

            try {
                $this->types[$key] = $this->analyse($closure, $classReflection);
            } catch (Throwable) {
                $this->types[$key] = null;
            } finally {
                unset($this->resolving[$key]);
            }
        }

        // A facade or subclass receiver reaches the class's macros, so a body calling it reads theirs.
        if ($receiver->getName() !== $classReflection->getName() && isset($this->classDependencies[$classReflection->getName()])) {
            $this->addClassDependencies($receiver->getName(), ...$this->classDependencies[$classReflection->getName()]);
        }

        return $this->types[$key];
    }

    /**
     * Analyse the closure's body in the class and declare what the body reads.
     */
    private function analyse(Closure $closure, ClassReflection $classReflection): ?ClosureType
    {
        $reflection = new ReflectionFunction($closure);

        // A first-class callable of a named function or method reflects that function, not a closure body.
        if (! str_starts_with($reflection->getName(), '{closure')) {
            return null;
        }

        $file = $reflection->getFileName();

        if ($file === false || ! is_file($file)) {
            return null;
        }

        $startLine = $reflection->getStartLine();
        $endLine = $reflection->getEndLine();
        $statements = $this->parser->parseFile($file);
        $nodeFinder = new NodeFinder;
        $candidates = $nodeFinder->find(
            $statements,
            static fn (Node $node): bool => ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction)
                && $node->getStartLine() === $startLine
                && $node->getEndLine() === $endLine,
        );

        // Closures that share their lines, including one nested in another, cannot be told apart.
        if (count($candidates) !== 1 || ! $candidates[0] instanceof Node\Expr) {
            return null;
        }

        $closureNode = $candidates[0];

        // Rebinding keeps __CLASS__ and __TRAIT__ where the closure is written, which entering the class would change.
        if ($nodeFinder->findFirst($closureNode, static fn (Node $node): bool => $node instanceof Node\Scalar\MagicConst\Class_ || $node instanceof Node\Scalar\MagicConst\Trait_) !== null) {
            return null;
        }

        $scope = $this->scopeFactory->create(ScopeContext::create($file));

        // The closure keeps its namespace, which resolves its unqualified functions and constants and __NAMESPACE__.
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Namespace_
                && $statement->name !== null
                && $statement->getStartFilePos() <= $closureNode->getStartFilePos()
                && $closureNode->getEndFilePos() <= $statement->getEndFilePos()) {
                $scope = $scope->enterNamespace($statement->name->toString());

                break;
            }
        }

        // Only an expression result carries both the closure's type and what its body reads, from one walk.
        // @phpstan-ignore phpstanApi.method (Processes the closure node outside the running analysis.), phpstanApi.constructor (The storage the on-demand walk requires.)
        $result = $this->container->getByType(NodeScopeResolver::class)->processExprOnDemand($closureNode, $scope->enterClass($classReflection), new ExpressionResultStorage);
        // @phpstan-ignore phpstanApi.method (The closure type from the same walk as its dependencies.)
        $type = $result->getType();

        if (! $type instanceof ClosureType) {
            return null;
        }

        // @phpstan-ignore phpstanApi.method (What the walked body reads, which the result cache needs.)
        [$classes, $files] = $this->readDependencies($result->getDependencies());

        // The class's own record already holds what its other macros read.
        foreach (array_keys($classes) as $className) {
            if ($className !== $classReflection->getName() && isset($this->classDependencies[$className])) {
                $classes += $this->classDependencies[$className][0];
                $files += $this->classDependencies[$className][1];
            }
        }

        foreach (array_keys($classes) as $className) {
            $this->declarationDependencyTracker->trackClassDependency($classReflection, $className);
        }

        foreach (array_keys($files) as $file) {
            $this->declarationDependencyTracker->trackFileDependency($classReflection, $file);
        }

        $this->addClassDependencies($classReflection->getName(), $classes, $files);

        return $type;
    }

    /**
     * Record class names and files read by the macro bodies the class reaches.
     *
     * @param array<string, true> $classes
     * @param array<string, true> $files
     */
    private function addClassDependencies(string $className, array $classes, array $files): void
    {
        $this->classDependencies[$className] ??= [[], []];
        $this->classDependencies[$className][0] += $classes;
        $this->classDependencies[$className][1] += $files;
    }

    /**
     * Get the class names and files the analysed body reads.
     *
     * @return array{array<string, true>, array<string, true>}
     */
    private function readDependencies(?Dependencies $dependencies): array
    {
        $classes = [];
        $files = [];

        // @phpstan-ignore phpstanApi.method (Dependencies offers no other way to read its values.)
        $dependencies?->walk(static function (string $file, array $types, array $classNames, array $reflections, array $filePaths, array $usedTraits) use (&$classes, &$files): void {
            foreach ($types as $type) {
                foreach ($type->getReferencedClasses() as $className) {
                    $classes[$className] = true;
                }
            }

            foreach ($classNames as $className) {
                $classes[$className] = true;
            }

            foreach ($usedTraits as $usedTrait) {
                $classes[$usedTrait->getName()] = true;
            }

            foreach ($reflections as $reflection) {
                $fileName = $reflection->getFileName();

                if ($fileName !== null) {
                    $files[$fileName] = true;
                }
            }

            foreach ($filePaths as $filePath) {
                $files[$filePath] = true;
            }
        });

        return [$classes, $files];
    }
}
