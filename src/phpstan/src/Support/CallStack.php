<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;

use function array_push;
use function array_reverse;

class CallStack
{
    /**
     * Get the calls whose arguments enclose the scope's code, innermost first, including the calls a
     * closure around it is passed to. A call may appear more than once.
     *
     * @return list<FunctionReflection|MethodReflection>
     */
    public static function enclosing(Scope $scope): array
    {
        $calls = [];

        // @TODO PHPStan's invalidateExpression() and invalidateMethodsOnExpression() discard the call stack,
        // so a callback loses its enclosing calls after a statement that invalidates an expression. A closure's
        // parent scope is where it was created and keeps them. Remove this recovery once the minimum supported
        // PHPStan version preserves the stack in both methods.
        for ($level = $scope; $level !== null; $level = $level->isInAnonymousFunction() ? $level->getParentScope() : null) {
            array_push($calls, ...array_reverse($level->getFunctionCallStack()));
        }

        return $calls;
    }
}
