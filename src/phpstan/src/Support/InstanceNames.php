<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Support;

use PhpParser\Node\Arg;
use PHPStan\Analyser\Scope;

/**
 * Resolves the instance names that a manager method's name argument can select.
 */
class InstanceNames
{
    /**
     * Get the instance names a name argument can select, with null for the default instance.
     *
     * Returns null when the argument can name an instance the analysis does not know.
     *
     * @return null|list<?string>
     */
    public static function fromArgument(?Arg $name, Scope $scope): ?array
    {
        if ($name === null) {
            return [null];
        }

        if ($name->unpack) {
            return null;
        }

        $names = [];

        foreach ($scope->getType($name->value)->getFiniteTypes() as $type) {
            if ($type->isNull()->yes()) {
                $names[] = null;
            } elseif ($type->getConstantStrings() !== []) {
                $names[] = $type->getConstantStrings()[0]->getValue();
            } elseif ($type->getEnumCases() !== []) {
                // enum_value() resolves a backed case to its value and a pure case to its name.
                $enumCase = $type->getEnumCases()[0];
                $names[] = (string) ($enumCase->getBackingValueType()?->getConstantScalarValues()[0] ?? $enumCase->getEnumCaseName());
            } else {
                return null;
            }
        }

        return $names === [] ? null : $names;
    }
}
