<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Schema;

use Hypervel\NestedSet\NestedSet;
use Hypervel\NestedSet\NestedSetServiceProvider;
use PHPStan\DependencyInjection\AutowiredService;

#[AutowiredService]
class NestedSetBlueprintMacros implements BlueprintMacroExtension
{
    /**
     * Get the nested set macros.
     */
    public function getMacros(): array
    {
        return array_fill_keys(['nestedSet', 'integerNestedSet', 'uuidNestedSet', 'ulidNestedSet', 'dropNestedSet'], NestedSetServiceProvider::class);
    }

    /**
     * Describe the bounds, depth and parent columns.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void
    {
        if ($macro === 'dropNestedSet') {
            foreach (NestedSet::getDefaultColumns() as $column) {
                $call->dropColumn($column);
            }

            return;
        }

        $call->addColumn('integer', NestedSet::LFT, unsigned: true);
        $call->addColumn('integer', NestedSet::RGT, unsigned: true);
        $call->addColumn('smallInteger', NestedSet::DEPTH, unsigned: true);

        [$type, $unsigned] = match ($macro) {
            'nestedSet' => ['bigInteger', true],
            'integerNestedSet' => ['integer', true],
            'uuidNestedSet' => ['uuid', false],
            default => ['char', false],
        };

        $call->addColumn($type, NestedSet::PARENT_ID, nullable: true, unsigned: $unsigned);
    }
}
