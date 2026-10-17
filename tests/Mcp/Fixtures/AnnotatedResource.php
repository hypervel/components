<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Server\Annotations\Audience;
use Hypervel\Mcp\Server\Annotations\LastModified;
use Hypervel\Mcp\Server\Annotations\Priority;
use Hypervel\Mcp\Server\Resource;

#[Audience([Role::User])]
#[Priority(0.7)]
#[LastModified('2026-05-01T00:00:00Z')]
class AnnotatedResource extends Resource
{
    protected string $uri = 'file://resources/annotated';

    protected string $mimeType = 'text/plain';

    /**
     * Handle the resource request.
     */
    public function handle(): string
    {
        return 'annotated content';
    }
}
