<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Resources;

use Hypervel\Mcp\Server\Resource;

class StaticTextResource extends Resource
{
    protected string $name = 'static-text';

    protected string $uri = 'test://static-text';

    protected string $mimeType = 'text/plain';

    protected string $description = 'A static text resource for testing';

    /**
     * Return the fixed resource text.
     */
    public function handle(): string
    {
        return 'This is the content of the static text resource.';
    }
}
