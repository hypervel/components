<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\Coroutine;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\DependencyInjection\Conditional;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function sprintf;
use function strtolower;

/**
 * Recommend the fakeable Sleep class over the native sleep() and usleep() functions.
 *
 * @implements Rule<FuncCall>
 */
#[Conditional(attribute: new AutowiredService, if: '%noRawSleepCalls%')]
class NoRawSleepCallsRule implements Rule
{
    /**
     * Create a new raw sleep calls rule instance.
     */
    public function __construct(
        private ReflectionProvider $reflectionProvider,
    ) {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * Report calls to the native sleep() and usleep() functions.
     *
     * @param FuncCall $node
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Name) {
            return [];
        }

        $function = strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '');

        if ($function !== 'sleep' && $function !== 'usleep') {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf('Call Sleep::%1$s() instead of %1$s(), so tests can fake the delay.', $function))
                ->identifier('hypervel.rawSleep')
                ->addTip('Keep the native call only where real time must pass, such as polling an external process.')
                ->build(),
        ];
    }
}
