<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\PHPStan\Concerns\InfersBoundHelperCalls;
use Hypervel\PHPStan\ReturnTypes\AppMakeHelper;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Type;
use Psr\Log\LoggerInterface;

/**
 * Type logger()'s logger as the booted binding, as for app(LoggerInterface::class).
 *
 * @internal
 */
#[AutowiredService]
class LoggerExtension implements DynamicFunctionReturnTypeExtension
{
    use InfersBoundHelperCalls;

    private ?Type $loggerType = null;

    /**
     * Create a logger() return type extension.
     */
    public function __construct(
        private readonly AppMakeHelper $appMakeHelper,
    ) {
    }

    /**
     * Determine whether the function is logger().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'logger';
    }

    /**
     * Get the logger for a null message, and otherwise null.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): Type {
        return $this->getBoundHelperType(
            $functionReflection,
            $functionCall,
            $scope,
            LoggerInterface::class,
            $this->loggerType ??= $this->appMakeHelper->resolveAbstractType(LoggerInterface::class),
        );
    }
}
