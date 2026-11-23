<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use Hypervel\Foundation\Application;
use Hypervel\Support\Env;
use Hypervel\Testbench\Foundation\Bootstrap\EnsuresDefaultConfiguration;
use Hypervel\Testbench\Foundation\Concerns\HandlesDatabaseConnections;
use Hypervel\Testing\ParallelTesting;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Parser\Parser;
use PHPStan\Parser\ParserErrorsException;
use PHPStan\Reflection\ReflectionProvider;

use function array_keys;
use function class_basename;
use function count;
use function explode;
use function file_get_contents;
use function function_exists;
use function implode;
use function in_array;
use function is_subclass_of;
use function preg_match;
use function preg_quote;
use function strtolower;

/**
 * Find the environment variables a set of PHP files can read.
 *
 * The scan is syntactic: it recognizes the framework's environment readers called with a
 * constant name. A read whose name it cannot determine could read any variable, so the whole
 * environment is selected, except inside the readers themselves and the framework methods
 * that build their names from known prefixes.
 *
 * @internal
 */
#[AutowiredService]
class EnvironmentVariableScanner
{
    /**
     * The global functions that read the variable named by their first argument.
     */
    private const array READER_FUNCTIONS = ['env', 'env_array', 'env_or_fail', 'getenv'];

    /**
     * The Env methods that read the variable named by their first argument.
     */
    private const array READER_METHODS = ['get', 'getarray', 'getorfail', 'has'];

    /**
     * The framework methods and functions that read variables by computed names, with the names a boot can read through them.
     *
     * Testbench's defined_environment_variables() copies every variable into a subprocess's
     * environment, which no boot-derived fact depends on.
     */
    private const array MODELED_READERS = [
        HandlesDatabaseConnections::class . '::usesDatabaseConnectionsEnvironmentVariables' => ['MYSQL_*', 'MARIADB_*', 'POSTGRES_*'],
        EnsuresDefaultConfiguration::class . '::bootstrap' => ['APP_KEY', 'APP_DEBUG', 'DB_CONNECTION'],
        Application::class . '::normalizeCachePath' => ['APP_*_CACHE'],
        ParallelTesting::class . '::option' => ['HYPERVEL_PARALLEL_TESTING_*'],
        'Hypervel\Testbench\defined_environment_variables' => [],
    ];

    /**
     * The lowercased names of Env and its subclasses loaded at boot, during a scan.
     *
     * @var array<string, true>
     */
    private array $envClasses = [];

    /**
     * Create a new scanner instance.
     */
    public function __construct(
        // Resolves names and keeps function bodies, which the cleaning parser drops, without the
        // rich parser's analysis visitors, which double the parse time.
        #[AutowiredParameter(ref: '@currentPhpVersionSimpleDirectParser')]
        private Parser $parser,
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the variable names and name patterns the files can read.
     *
     * The patterns of the modeled framework readers are included whenever their class was loaded
     * at boot, scanned or not. A '*' pattern selects every variable.
     *
     * @param list<string> $files
     * @param array<string, true> $classes the lowercased names of the classes, interfaces and traits loaded at boot
     * @return array{names: list<string>, patterns: list<string>}
     */
    public function scan(array $files, array $classes): array
    {
        $patterns = [];

        foreach (self::MODELED_READERS as $method => $readerPatterns) {
            if (isset($classes[strtolower(explode('::', $method)[0])])) {
                foreach ($readerPatterns as $pattern) {
                    $patterns[$pattern] = true;
                }
            }
        }

        $this->envClasses = [strtolower(Env::class) => true];
        $subclassNames = [];

        foreach (array_keys($classes) as $class) {
            if (is_subclass_of($class, Env::class)) {
                $this->envClasses[$class] = true;
                $subclassNames[] = preg_quote(class_basename($class), '/');
            }
        }

        // Every recognized read names a reader (env(), getenv(), an Env class) or a superglobal.
        $candidate = '/\$_SERVER\b|env(?:_array|_or_fail)?\b' . ($subclassNames === [] ? '' : '|\b(?:' . implode('|', $subclassNames) . ')\b') . '/i';
        $visitor = new EnvironmentReadVisitor($this);
        $traverser = new NodeTraverser($visitor);

        foreach ($files as $file) {
            if (preg_match($candidate, (string) file_get_contents($file)) !== 1) {
                continue;
            }

            try {
                $statements = $this->parser->parseFile($file);
            } catch (ParserErrorsException) {
                return ['names' => [], 'patterns' => ['*']];
            }

            $traverser->traverse($statements);

            if ($visitor->dynamic) {
                return ['names' => [], 'patterns' => ['*']];
            }
        }

        return ['names' => array_keys($visitor->names), 'patterns' => array_keys($patterns)];
    }

    /**
     * Get the variable a node reads: its name, '' when the name is not constant, or null when it reads none.
     *
     * @param null|string $class the class enclosing the node
     */
    public function readName(Node $node, ?string $class): ?string
    {
        if ($node instanceof ArrayDimFetch
            && $node->var instanceof Variable
            && in_array($node->var->name, ['_ENV', '_SERVER'], true)) {
            return $node->dim instanceof String_ ? $node->dim->value : '';
        }

        if ($node instanceof FuncCall && $node->name instanceof Name && $this->isReaderFunction($node->name)) {
            return $this->argumentName($node, ['key', 'name']);
        }

        if ($node instanceof StaticCall
            && $node->class instanceof Name
            && $node->name instanceof Identifier
            && $this->isEnv($node->class, $class)) {
            if ($node->name->toLowerString() === 'getrepository') {
                return null;
            }

            return in_array($node->name->toLowerString(), self::READER_METHODS, true)
                ? $this->argumentName($node, ['key'])
                : null;
        }

        // Env::getRepository()->get($name) reads through the repository directly.
        if ($node instanceof MethodCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'get'
            && $node->var instanceof StaticCall
            && $node->var->class instanceof Name
            && $node->var->name instanceof Identifier
            && $node->var->name->toLowerString() === 'getrepository'
            && $this->isEnv($node->var->class, $class)) {
            return $this->argumentName($node, ['name']);
        }

        return null;
    }

    /**
     * Determine whether a computed name in the given function is accounted for.
     *
     * The readers pass their caller's name through, and the modeled framework methods read
     * names their patterns cover.
     */
    public function readsComputedNames(?string $class, ?string $function): bool
    {
        if ($class !== null && $this->isEnvClass($class)) {
            return true;
        }

        if ($class === null && $function !== null && in_array(strtolower($function), self::READER_FUNCTIONS, true)) {
            return true;
        }

        return $function !== null && isset(self::MODELED_READERS[$function]);
    }

    /**
     * Determine whether a function name resolves to a global environment reader.
     */
    private function isReaderFunction(Name $name): bool
    {
        if (! in_array($name->toLowerString(), self::READER_FUNCTIONS, true)) {
            return false;
        }

        // An unqualified call in a namespace runs that namespace's function when one exists.
        $namespacedName = $name->getAttribute('namespacedName');

        return ! $namespacedName instanceof Name || ! function_exists($namespacedName->toString());
    }

    /**
     * Determine whether a class name is the Env class or a subclass, where self, static and parent
     * name one when the enclosing class is one.
     */
    private function isEnv(Name $name, ?string $class): bool
    {
        if ($name->isSpecialClassName()) {
            return $class !== null && $this->isEnvClass($class);
        }

        return $this->isEnvClass($name->toString());
    }

    /**
     * Determine whether a class is the Env class or a subclass loaded at boot.
     */
    private function isEnvClass(string $class): bool
    {
        return isset($this->envClasses[strtolower($class)]);
    }

    /**
     * Get the constant variable name a reader call passes, or '' when it is not constant.
     *
     * @param list<string> $parameterNames the reader's name parameter
     */
    private function argumentName(CallLike $call, array $parameterNames): string
    {
        // A first-class callable, such as env(...), can later read any name.
        if ($call->isFirstClassCallable()) {
            return '';
        }

        $argument = null;

        foreach ($call->getArgs() as $index => $candidate) {
            if ($candidate->unpack) {
                return '';
            }

            if ($candidate->name === null ? $index === 0 : in_array($candidate->name->toString(), $parameterNames, true)) {
                $argument = $candidate;

                break;
            }
        }

        return $argument === null ? '' : $this->constantString($argument->value);
    }

    /**
     * Get the value of a constant string expression, or '' when it is not one.
     */
    private function constantString(Expr $expression): string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof ClassConstFetch
            && $expression->class instanceof Name
            && $expression->name instanceof Identifier
            && $this->reflectionProvider->hasClass($expression->class->toString())) {
            $class = $this->reflectionProvider->getClass($expression->class->toString());

            if ($class->hasConstant($expression->name->toString())) {
                $values = $class->getConstant($expression->name->toString())->getValueType()->getConstantStrings();

                return count($values) === 1 ? $values[0]->getValue() : '';
            }
        }

        return '';
    }
}
