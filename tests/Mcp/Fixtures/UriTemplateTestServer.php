<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Server;

class UriTemplateTestServer extends Server
{
    protected array $resources = [
        UriTemplateSummaryResource::class,
        UriTemplateUserFileResource::class,
    ];
}
