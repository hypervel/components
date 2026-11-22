<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Schema;

use Hypervel\Scout\SearchableSchema;
use PHPStan\DependencyInjection\AutowiredService;

#[AutowiredService]
class SearchableBlueprintMacros implements BlueprintMacroExtension
{
    /**
     * Get Scout's search macros.
     */
    public function getMacros(): array
    {
        return ['searchable' => SearchableSchema::class, 'dropSearchable' => SearchableSchema::class];
    }

    /**
     * Describe the search vector column.
     */
    public function apply(string $macro, BlueprintMacroCall $call): void
    {
        if ($macro === 'searchable') {
            $call->addColumn('tsvector', $call->name(3, 'tsvector', 'search_vector'));
        } else {
            $call->dropColumn($call->name(1, 'tsvector', 'search_vector'));
        }
    }
}
