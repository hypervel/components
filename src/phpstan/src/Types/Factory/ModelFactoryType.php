<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Types\Factory;

use PHPStan\Reflection\ClassReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\CompoundType;
use PHPStan\Type\IsSuperTypeOfResult;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class ModelFactoryType extends ObjectType
{
    private TrinaryLogic $isSingleModel;

    /**
     * Create a model factory type.
     */
    public function __construct(
        string $className,
        ?Type $subtractedType = null,
        ?ClassReflection $classReflection = null,
        ?TrinaryLogic $isSingleModel = null,
    ) {
        parent::__construct($className, $subtractedType, $classReflection);

        $this->isSingleModel = $isSingleModel ?? TrinaryLogic::createMaybe();
    }

    /**
     * Determine whether the factory makes a single model rather than a collection.
     */
    public function isSingleModel(): TrinaryLogic
    {
        return $this->isSingleModel;
    }

    /**
     * Get the same factory with the given count.
     */
    public function withSingleModel(TrinaryLogic $isSingleModel): self
    {
        return new self($this->getClassName(), null, $this->getClassReflection(), $isSingleModel);
    }

    /**
     * Determine whether the type is a supertype of the given type.
     *
     * Unlike upstream, a known count covers another factory only when that factory is known to have the same
     * count, so merging branches whose counts differ keeps them apart instead of keeping whichever came first.
     */
    public function isSuperTypeOf(Type $type): IsSuperTypeOfResult
    {
        $result = parent::isSuperTypeOf($type);

        if (! $result->yes() || $this->isSingleModel->maybe() || $type instanceof CompoundType) {
            return $result;
        }

        return $type instanceof self && $type->isSingleModel->equals($this->isSingleModel)
            ? $result
            : IsSuperTypeOfResult::createMaybe();
    }

    /**
     * Determine whether the type equals the given type, including its count.
     */
    public function equals(Type $type): bool
    {
        return parent::equals($type) && $type instanceof self && $type->isSingleModel->equals($this->isSingleModel);
    }

    /**
     * Get the count, which keeps PHPStan's cached comparisons apart for factories whose counts differ.
     */
    protected function describeAdditionalCacheKey(): string
    {
        return '-' . $this->isSingleModel->describe();
    }
}
