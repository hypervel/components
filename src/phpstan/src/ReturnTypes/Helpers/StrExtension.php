<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\PHPStan\Concerns\InfersFactoryHelperCalls;
use Hypervel\Support\Stringable;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

#[AutowiredService]
class StrExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersFactoryHelperCalls;

    private ?ObjectType $stringableType = null;

    private ?MixedType $mixedType = null;

    /**
     * Determine whether the function is str().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'str';
    }

    /**
     * Get mixed for a call without arguments, and otherwise a stringable.
     *
     * Without arguments, str() returns an anonymous class that passes method calls to Str. This
     * extension does not model that proxy, so it keeps upstream's mixed type instead of the
     * declared object type, which would reject every call.
     */
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): ?Type
    {
        // Upstream counts arguments, so it types every call with unpacked arguments as a stringable, though they may be empty.
        return $this->getFactoryHelperType(
            $functionCall,
            fn (): Type => $this->mixedType ??= new MixedType,
            $this->stringableType ??= new ObjectType(Stringable::class),
        );
    }
}
