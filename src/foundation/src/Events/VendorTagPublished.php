<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Events;

class VendorTagPublished
{
    /**
     * Create a new event instance.
     *
     * @param null|string $tag the vendor tag that was published
     * @param array $paths the publishable paths registered by the tag
     */
    public function __construct(
        public readonly ?string $tag,
        public readonly array $paths,
    ) {
    }
}
