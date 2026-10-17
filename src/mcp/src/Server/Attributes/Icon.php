<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Attributes;

use Attribute;
use Hypervel\Mcp\Enums\IconTheme;
use Hypervel\Mcp\Schema\Icon as IconValue;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class Icon
{
    /**
     * Create an icon attribute.
     *
     * @param list<string> $sizes
     */
    public function __construct(
        public string $src,
        public ?string $mimeType = null,
        public array $sizes = [],
        public ?IconTheme $theme = null,
    ) {
    }

    /**
     * Resolve the attribute to an icon value.
     */
    public function toIcon(): IconValue
    {
        return IconValue::from($this->src, $this->mimeType, $this->sizes, $this->theme);
    }
}
