<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\SchemaAggregator;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\ColumnDefinition;
use Hypervel\PHPStan\Schema\BlueprintMacroCall;
use Hypervel\PHPStan\Schema\BlueprintMacroExtension;

class ChainBlueprintMacros implements BlueprintMacroExtension
{
    /**
     * Register chainable macros and a dropColumn macro shadowed by Blueprint's own method.
     */
    public static function register(): void
    {
        Blueprint::macro('dropColumn', static function (): void {});
        Blueprint::macro('sameBlueprint', function (): Blueprint {
            return $this;
        });
        Blueprint::macro('firstColumn', function (): ColumnDefinition {
            return $this->getColumns()[0];
        });
    }

    /**
     * Get the macros that leave the table as they found it.
     */
    public function getMacros(): array
    {
        return ['dropColumn' => self::class, 'sameBlueprint' => self::class, 'firstColumn' => self::class];
    }

    /**
     * Describe the call before any chained methods run.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void
    {
    }
}
