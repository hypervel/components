<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Telescope\Aspects\GuzzleHttpClientAspect;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'aspects' => [
                GuzzleHttpClientAspect::class,
            ],
        ];
    }
}
