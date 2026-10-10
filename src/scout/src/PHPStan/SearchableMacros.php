<?php

declare(strict_types=1);

namespace Hypervel\Scout\PHPStan;

/**
 * Declare the builder macros that SearchableScope adds, for static analysis.
 *
 * Keep these signatures in step with the scope's macros.
 */
interface SearchableMacros
{
    /**
     * Make all of the matching models searchable.
     */
    public function searchable(?int $chunk = null): void;

    /**
     * Remove all of the matching models from the search index.
     */
    public function unsearchable(?int $chunk = null): void;
}
