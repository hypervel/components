<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Sentry\Aspects\CoroutineAspect;
use Hypervel\Sentry\Aspects\GuzzleHttpClientAspect;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'aspects' => [
                GuzzleHttpClientAspect::class,
                CoroutineAspect::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        \Sentry\SentrySdk::class => __DIR__ . '/../class-map/SentrySdk.php',
                    ],
                ],
            ],
        ];
    }
}
