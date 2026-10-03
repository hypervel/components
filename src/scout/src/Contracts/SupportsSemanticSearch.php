<?php

declare(strict_types=1);

namespace Hypervel\Scout\Contracts;

/**
 * Contract for engines that support semantic and hybrid search.
 *
 * Semantic searches fail on other engines, while hybrid searches fall back
 * to their normal full-text search.
 */
interface SupportsSemanticSearch
{
}
