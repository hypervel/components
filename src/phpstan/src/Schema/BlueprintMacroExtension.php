<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Schema;

use PHPStan\DependencyInjection\ExtensionInterface;

#[ExtensionInterface(self::TAG)]
interface BlueprintMacroExtension
{
    public const string TAG = 'hypervel.blueprintMacroExtension';

    /**
     * Get the macros and the class that registers each closure.
     *
     * @return array<string, class-string>
     */
    public function getMacros(): array;

    /**
     * Describe the column changes made by a macro call.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void;
}
