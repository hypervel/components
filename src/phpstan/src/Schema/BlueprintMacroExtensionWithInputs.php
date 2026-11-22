<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Schema;

interface BlueprintMacroExtensionWithInputs extends BlueprintMacroExtension
{
    /**
     * Get the helper classes and configuration values used to describe columns.
     *
     * @return array{classes?: list<class-string>, configuration?: array<string, null|scalar>}
     */
    public function getInputs(): array;
}
