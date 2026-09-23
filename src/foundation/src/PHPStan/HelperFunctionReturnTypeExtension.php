<?php

declare(strict_types=1);

namespace Hypervel\Foundation\PHPStan;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Validation\Factory as ValidationFactory;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Http\Response;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

class HelperFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * Determine whether the helper selects its return type by argument count.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return in_array($functionReflection->getName(), ['response', 'validator'], true);
    }

    /**
     * Infer the helper result without treating an explicit null as an omitted argument.
     */
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
    {
        [$factory, $result] = $functionReflection->getName() === 'response'
            ? [ResponseFactory::class, Response::class]
            : [ValidationFactory::class, Validator::class];

        $arguments = $functionCall->getArgs();

        if ($arguments === []) {
            return new ObjectType($factory);
        }

        foreach ($arguments as $argument) {
            if (! $argument->unpack) {
                return new ObjectType($result);
            }
        }

        return TypeCombinator::union(new ObjectType($factory), new ObjectType($result));
    }
}
