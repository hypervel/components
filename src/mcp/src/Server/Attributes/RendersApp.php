<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

use Attribute;
use Hypervel\Mcp\Server\Ui\Enums\Visibility;

#[Attribute(Attribute::TARGET_CLASS)]
class RendersApp
{
    /**
     * Associate a tool with an application resource.
     *
     * @param class-string $resource
     * @param array<int, Visibility> $visibility
     */
    public function __construct(
        public string $resource,
        public array $visibility = [Visibility::Model, Visibility::App],
    ) {
    }
}
