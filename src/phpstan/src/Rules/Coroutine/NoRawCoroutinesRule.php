<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\PHPStan\Support\FacadeHelper;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Swoole\Coroutine as SwooleCoroutine;

use function in_array;
use function sprintf;
use function strtolower;

/**
 * Reports coroutines started directly through Swoole or the engine.
 *
 * An exception that escapes such a coroutine, including a cancellation, is a fatal error that stops
 * the worker. The framework's coroutines catch and report it.
 *
 * @implements Rule<CallLike>
 *
 * @internal
 */
#[Conditional(attribute: new AutowiredService, if: '%noRawCoroutines%')]
class NoRawCoroutinesRule implements Rule
{
    /**
     * The function that starts a raw coroutine. Swoole's global go() needs short names, which the framework rejects.
     */
    private const string FUNCTION = 'swoole\coroutine\go';

    /**
     * The declaring classes of the methods that start a raw coroutine, by lowercase method name.
     */
    private const array METHODS = [
        'create' => [SwooleCoroutine::class, EngineCoroutine::class],
        'execute' => [EngineCoroutine::class],
    ];

    /**
     * Create a new raw coroutines rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
        private FacadeHelper $facadeHelper,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * Report a call that starts a raw coroutine.
     *
     * @param CallLike $node
     * @param DependencyTracker&Scope $scope
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $spawn = null;

        if ($node instanceof FuncCall) {
            $function = $node->name instanceof Name ? $this->reflectionProvider->resolveFunctionName($node->name, $scope) : null;

            if ($function !== null && strtolower($function) === self::FUNCTION) {
                $spawn = $function;
            }
        } elseif (($node instanceof MethodCall || $node instanceof StaticCall)
            && $node->name instanceof Identifier
            && isset(self::METHODS[strtolower($node->name->toString())])
        ) {
            // PHPStan also analyzes a nullsafe call as a method call.
            foreach ($this->facadeHelper->getCalledMethods($node, $node->name->toString(), $scope) as $method) {
                $class = $method->getDeclaringClass()->getName();

                if (in_array($class, self::METHODS[strtolower($method->getName())], true)) {
                    $spawn = $class . '::' . $method->getName();

                    break;
                }
            }
        }

        if ($spawn === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('%s() starts a coroutine without the framework\'s exception handling, so an uncaught exception or cancellation stops the worker.', $spawn))
                ->identifier('hypervel.rawCoroutine')
                ->addTip('Use Hypervel\Coroutine\Coroutine::create(), go() or co() instead. Coroutine::fork() and the helpers\' copyContext argument copy the parent\'s context. Keep a direct call, with an ignore, only when its callback catches every exception itself.')
                ->build(),
        ];
    }
}
