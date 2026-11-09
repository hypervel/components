<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Hypervel\PHPStan\Support\CallStack;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Testing\RuleTestCase;

use function array_map;
use function array_unique;
use function implode;

/** @extends RuleTestCase<Rule<FuncCall>> */
class CallStackTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new class implements Rule {
            /**
             * Get the node type the rule checks.
             */
            public function getNodeType(): string
            {
                return FuncCall::class;
            }

            /**
             * Report the distinct calls enclosing each enclosingCalls() marker, innermost first.
             *
             * @param FuncCall $node
             */
            public function processNode(Node $node, Scope $scope): array
            {
                if (! $node->name instanceof Name || $node->name->getLast() !== 'enclosingCalls') {
                    return [];
                }

                $calls = array_unique(array_map(
                    static fn (FunctionReflection|MethodReflection $call): string => $call instanceof MethodReflection
                        ? $call->getDeclaringClass()->getName() . '::' . $call->getName()
                        : $call->getName(),
                    CallStack::enclosing($scope),
                ));

                return [RuleErrorBuilder::message(implode(', ', $calls))->identifier('test.callStack')->build()];
            }
        };
    }

    public function testCallbackKeepsEnclosingCallsAfterInvalidation(): void
    {
        $this->analyse([__DIR__ . '/../data/call-stack.php'], [
            ['Hypervel\Support\Facades\DB::usingConnection, Hypervel\Support\Facades\DB::transaction', 29],
        ]);
    }
}
