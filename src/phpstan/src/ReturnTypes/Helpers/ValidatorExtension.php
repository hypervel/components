<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\Contracts\Validation\Factory;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\PHPStan\Concerns\InfersFactoryHelperCalls;
use Hypervel\PHPStan\ReturnTypes\AppMakeHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/** @internal */
#[AutowiredService]
class ValidatorExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersFactoryHelperCalls;

    private ?ObjectType $validatorType = null;

    /**
     * Create a validator() return type extension.
     */
    public function __construct(
        private readonly AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function is validator().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'validator';
    }

    /**
     * Get the factory for a call without arguments, and otherwise the validator.
     *
     * @param DependencyTracker&Scope $scope
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        // Upstream counts arguments, so it types every call with unpacked arguments as a validator, though they may be empty.
        // It also claims the concrete validator, but the helper makes one through the bound factory
        // contract, whose make() promises only the validator contract.
        // Hypervel: the factory is the booted binding, as for app(Factory::class), rather than the contract.
        return $this->getFactoryHelperType(
            $functionCall,
            fn (): Type => $this->appMakeHelper->resolveAbstractType(Factory::class, $scope),
            $this->validatorType ??= new ObjectType(Validator::class),
        );
    }
}
