<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

use Hypervel\Pagination\Listeners\PageResolverListener;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'listeners' => [
                PageResolverListener::class,
            ],
            'publish' => [],
        ];
    }
}
