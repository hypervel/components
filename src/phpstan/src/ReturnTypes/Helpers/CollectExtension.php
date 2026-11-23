<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes\Helpers;

use Hypervel\PHPStan\Support\CollectionHelper;
use Hypervel\Support\Collection;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\BenevolentUnionType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

use function count;

#[AutowiredService]
class CollectExtension implements DynamicFunctionReturnTypeExtension
{
    private ?Type $emptyCollectionType = null;

    /**
     * Create a new extension instance.
     *
     * With PHPStan's unresolvedTemplateArguments feature toggle, the template arguments of an
     * empty collection are inferred from how it is used, so the extension must not fix them.
     */
    public function __construct(
        private CollectionHelper $collectionHelper,
        #[AutowiredParameter(ref: '%featureToggles.unresolvedTemplateArguments%')]
        private bool $inferFromUsage,
    ) {
    }

    /**
     * Determine whether the function is collect().
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'collect';
    }

    /**
     * Get the collection type for the collected value.
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope,
    ): ?Type {
        if (count($functionCall->getArgs()) < 1) {
            if ($this->inferFromUsage) {
                return null;
            }

            return $this->emptyCollectionType ??= new GenericObjectType(Collection::class, [new BenevolentUnionType([new IntegerType, new StringType]), new MixedType]);
        }

        $argument = $functionCall->getArgs()[0];

        // An unpacked argument's expression is the list holding the value, not the value.
        if ($argument->unpack) {
            return null;
        }

        $valueType = $scope->getType($argument->value);

        if ($this->inferFromUsage && $valueType->isArray()->yes() && $valueType->isIterableAtLeastOnce()->no()) {
            return null;
        }

        return $this->collectionHelper->determineGenericCollectionTypeFromType($valueType);
    }
}
