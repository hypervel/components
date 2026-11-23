<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\ReturnTypes;

use Hypervel\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\ConstantTypeHelper;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;

use function array_slice;
use function count;
use function in_array;

#[AutowiredService]
class ModelOnlyDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    /**
     * Get the class the extension applies to.
     */
    public function getClass(): string
    {
        return Model::class;
    }

    /**
     * Determine whether the method is the model's own only().
     */
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        // An override decides its own result.
        return $methodReflection->getName() === 'only'
            && $methodReflection->getDeclaringClass()->getName() === Model::class;
    }

    /**
     * Return the attribute shapes the given keys select.
     *
     * Each alternative of the first argument selects its own keys, so `only($flag ? 'name' : 'email')`
     * returns `array{name: T}|array{email: T}` rather than one shape with both keys. Within one shape,
     * a later value with several possible keys makes each of them optional, which keeps the work
     * linear in the number of keys.
     */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): ?Type {
        $slots = [];

        foreach ($methodCall->getArgs() as $argument) {
            $type = $scope->getType($argument->value);

            if (! $argument->unpack) {
                $slots[] = [$type, true];

                continue;
            }

            // String keys unpack as named arguments.
            if (! $type->getIterableKeyType()->isInteger()->yes()) {
                return null;
            }

            $alternatives = $this->valueSlots($type);

            // Several alternatives merge into one slot that holds any number of their values.
            $slots = [
                ...$slots,
                ...(count($alternatives) === 1 ? $alternatives[0] : [[$type->getIterableValueType(), false]]),
            ];
        }

        // An optional first value hides which argument only() reads first.
        if ($slots === [] || ! $slots[0][1]) {
            return null;
        }

        $shapes = [];

        foreach (TypeUtils::flattenTypes($slots[0][0]) as $firstType) {
            // only() reads an array first argument alone, and otherwise every argument as a key.
            if ($firstType->isArray()->yes()) {
                foreach ($this->valueSlots($firstType) as $valueSlots) {
                    $shapes[] = $this->keyShape($valueSlots);
                }
            } else {
                $shapes[] = $this->keyShape([[$firstType, true], ...array_slice($slots, 1)]);
            }
        }

        if (in_array(null, $shapes, true)) {
            return null;
        }

        $model = $scope->getType($methodCall->var);
        $results = [];

        foreach ($shapes as $keys) {
            $builder = ConstantArrayTypeBuilder::createEmpty();

            // A numeric key name becomes an integer offset, as it does in the result.
            foreach ($keys as $name => $required) {
                $builder->setOffsetValueType(ConstantTypeHelper::getTypeFromValue($name), $this->attributeType($model, (string) $name, $scope), ! $required);
            }

            $results[] = $builder->getArray();
        }

        return TypeCombinator::union(...$results);
    }

    /**
     * Return the keys that key slots select, each mapped to whether it is always present.
     *
     * Null means a key is not a known string.
     *
     * @param list<array{Type, bool}> $slots each slot's key type and whether the slot is always present
     * @return null|array<string, bool>
     */
    private function keyShape(array $slots): ?array
    {
        $shape = [];

        foreach ($slots as [$type, $required]) {
            $keys = $type->getConstantStrings();

            if ($keys === []) {
                return null;
            }

            // A slot with several possible keys selects only one of them.
            $present = $required && count($keys) === 1;

            foreach ($keys as $key) {
                $name = $key->getValue();
                $shape[$name] = $present || ($shape[$name] ?? false);
            }
        }

        return $shape;
    }

    /**
     * Return the value slots of each alternative of an array, each with whether it is always present.
     *
     * @return list<list<array{Type, bool}>>
     */
    private function valueSlots(Type $type): array
    {
        $alternatives = [];

        foreach ($type->getArrays() as $array) {
            $constantArrays = $array->getConstantArrays();

            // An array of unknown size may hold any number of its values.
            if ($constantArrays === []) {
                $alternatives[] = [[$array->getIterableValueType(), false]];

                continue;
            }

            foreach ($constantArrays as $constantArray) {
                $slots = [];

                foreach ($constantArray->getValueTypes() as $offset => $valueType) {
                    $slots[] = [$valueType, ! $constantArray->isOptionalKey($offset)];
                }

                $alternatives[] = $slots;
            }
        }

        return $alternatives;
    }

    /**
     * Return the type getAttribute() reads for the key.
     *
     * Unlike upstream, an unknown key reads as mixed rather than null, as the property does: the
     * value can be a selected alias that no column or accessor declares.
     */
    private function attributeType(Type $model, string $name, Scope $scope): Type
    {
        return $model->hasInstanceProperty($name)->yes()
            ? $model->getInstanceProperty($name, $scope)->getReadableType()
            : new MixedType;
    }
}
