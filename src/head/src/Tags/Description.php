<?php

declare(strict_types=1);

namespace Hypervel\Head\Tags;

use Hypervel\Head\Rendering\ResolvedHead;
use Hypervel\Head\Rendering\TagRenderer;

class Description extends StringTagBuilder
{
    /**
     * Get the builder key.
     */
    public static function key(): string
    {
        return 'description';
    }

    /**
     * Render the description meta tag.
     *
     * @return array<int, string>
     */
    public function toTags(ResolvedHead $head, TagRenderer $tags): array
    {
        $description = $this->render();

        return $description !== null && $description !== ''
            ? [$tags->meta('name', 'description', $description, 'description')]
            : [];
    }
}
