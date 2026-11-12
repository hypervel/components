<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Concerns;

use Closure;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/** @internal */
trait InfersFactoryHelperCalls
{
    /**
     * Get the type of a helper that returns its factory only when called without arguments.
     *
     * An explicit null still makes a result, so the helper's conditional return type on its
     * first parameter cannot tell the cases apart. Unpacked arguments may be empty.
     *
     * @param Closure(): Type $factoryType called only when the call can return the factory
     */
    protected function getFactoryHelperType(FuncCall $functionCall, Closure $factoryType, Type $resultType): Type
    {
        $arguments = $functionCall->getArgs();

        if ($arguments === []) {
            return $factoryType();
        }

        foreach ($arguments as $argument) {
            if (! $argument->unpack) {
                return $resultType;
            }
        }

        return TypeCombinator::union($factoryType(), $resultType);
    }
}
