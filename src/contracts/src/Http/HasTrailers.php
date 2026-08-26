<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

interface HasTrailers
{
    /**
     * Get the trailer names known before response emission.
     *
     * @return list<string>
     */
    public function trailerNames(): array;

    /**
     * Get the final response trailers.
     *
     * @return array<array-key, string>
     */
    public function trailers(): array;
}
