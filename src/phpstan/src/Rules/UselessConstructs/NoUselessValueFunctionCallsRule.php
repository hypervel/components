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
use PHPStan\Type\ClosureType;
use PHPStan\Type\MixedType;

use function in_array;
use function strtolower;

/**
 * Upstream matches the written name and takes the first argument as the value, so an
 * unpacked list or a reordered named argument was misread. Its fix dropped the evaluation
 * of the other arguments, and took the value from the analyzed tree instead of the fixer's
 * copy of the call, which broke the fixed file for values such as `new Foo()`.
 *
 * @implements Rule<FuncCall>
 */
#[AutowiredService]
class NoUselessValueFunctionCallsRule implements Rule
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
     * Report value() calls whose value is never a Closure.
     *
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->name instanceof Node\Name || strtolower($this->reflectionProvider->resolveFunctionName($node->name, $scope) ?? '') !== 'value') {
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

        if ($scope->getType($valueArg->value)->isSuperTypeOf(new ClosureType([], new MixedType, true))->no() === false) {
            return [];
        }

        $error = RuleErrorBuilder::message("Calling the helper function 'value()' without a closure as the first argument simply returns the first argument without doing anything")
            ->line($node->getStartLine())
            ->identifier('hypervel.uselessConstructs.value');

        // value() still evaluates its other arguments, so the call is replaced only when they are null, true or false.
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
