<?php

declare(strict_types=1);

namespace Hypervel\Dogfood\Mcp\Conformance\Resources;

use Hypervel\Dogfood\Mcp\Conformance\Fixtures;
use Hypervel\Mcp\Response;
use Hypervel\Mcp\Server\Resource;

class StaticBinaryResource extends Resource
{
    protected string $name = 'static-binary';

    protected string $uri = 'test://static-binary';

    protected string $mimeType = 'image/png';

    protected string $description = 'A static binary resource (image) for testing';

    /**
     * Return the binary image fixture.
     */
    public function handle(): Response
    {
        return Response::blob(base64_decode(Fixtures::IMAGE_BASE64, true));
    }
}
