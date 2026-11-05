<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Rules\UselessConstructs;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

use function in_array;
use function strtolower;

/**
 * Upstream matches the written name and takes the first two arguments by position, so an
 * unpacked list or reordered named arguments were misread. It also reported callbacks that
 * are not callable, which throw instead of returning the value. Its fix dropped the
 * evaluation of the other arguments, and took the value from the analyzed tree instead of
 * the fixer's copy of the call, which broke the fixed file for values such as `new Foo()`.
 *
 * @implements Rule<FuncCall>
 */
#[AutowiredService]
class NoUselessWithFunctionCallsRule implements Rule
{
    /**
     * Create a new rule instance.
     */
    public function __construct(private ReflectionProvider $reflectionProvider)
    {
    }

    /**
     * Get the node type the rule checks.
     */
    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    /**
     * Report with() calls whose callback is missing or null.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Name || strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '') !== 'with') {
            return [];
        }

        $args = $node->getArgs();

        foreach ($args as $arg) {
            if ($arg->unpack) {
                return [];
            }
        }

        $valueArg = $node->getArg('value', 0);

        if ($valueArg === null) {
            return [];
        }

        $callbackArg = $node->getArg('callback', 1);

        // Any other value that is not callable fails the ?callable parameter, so only null returns the value.
        if ($callbackArg === null) {
            $message = "Calling the helper function 'with()' with only one argument simply returns the value itself. If you want to chain methods on a construct, use '(new ClassName())->foo()' instead";
        } elseif ($scope->getType($callbackArg->value)->isNull()->yes()) {
            $message = "Calling the helper function 'with()' without a callable as the second argument simply returns the value without doing anything";
        } else {
            return [];
        }

        $error = RuleErrorBuilder::message($message)
            ->line($node->getStartLine())
            ->identifier('hypervel.uselessConstructs.with');

        // with() still evaluates its other arguments, so the call is replaced only when they are null, true or false.
        foreach ($args as $arg) {
            if ($arg !== $valueArg && ! ($arg->value instanceof ConstFetch && in_array($arg->value->name->toLowerString(), ['null', 'true', 'false'], true))) {
                return [$error->build()];
            }
        }

        return [$error->fixNode($node, static function (FuncCall $call): Node {
            // The fixer passes a copy of the call from its own parse of the file; a node from the
            // analyzed tree has positions the fixer's printer cannot use.
            /** @var Arg $valueArg */
            $valueArg = $call->getArg('value', 0);

            return $valueArg->value;
        })->build()];
    }
}
