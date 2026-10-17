<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Concerns;

use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Attributes\Icon as IconAttribute;

trait HasIcons
{
    use ReadsAttributes;

    /**
     * Resolve icons from attributes and the primitive's icons method.
     *
     * @return list<Icon>
     */
    public function resolvedIcons(): array
    {
        $attributeIcons = array_map(
            fn (IconAttribute $icon): Icon => $icon->toIcon(),
            $this->resolveAttributes(IconAttribute::class),
        );

        return [...$attributeIcons, ...$this->icons()];
    }
}
