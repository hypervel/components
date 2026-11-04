<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Routing\ResponseFactory;
use Hypervel\Contracts\Validation\Factory as ValidationFactory;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Http\Response;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

#[AutowiredService]
class HelperFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * The factory and result types of each helper.
     */
    private const array HELPERS = [
        'response' => [ResponseFactory::class, Response::class],
        'validator' => [ValidationFactory::class, Validator::class],
        'view' => [ViewFactory::class, ViewContract::class],
    ];

    /**
     * Determine whether the helper selects its return type by argument count.
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return isset(self::HELPERS[$functionReflection->getName()]);
    }

    /**
     * Infer the helper result without treating an explicit null as an omitted argument.
     */
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): Type
    {
        [$factory, $result] = self::HELPERS[$functionReflection->getName()];

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
