<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Resources;

use Hypervel\Mcp\Server\Resource;

class WatchedResource extends Resource
{
    protected string $name = 'watched-resource';

    protected string $uri = 'test://watched-resource';

    protected string $mimeType = 'text/plain';

    protected string $description = 'A resource that can be watched';

    /**
     * Return the watched resource's current content.
     */
    public function handle(): string
    {
        return 'Watched resource content';
    }
}
