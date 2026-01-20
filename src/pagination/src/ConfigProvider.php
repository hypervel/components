<?php

declare(strict_types=1);

namespace Hypervel\Pagination;

class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
        ];
    }
}
