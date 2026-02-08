<?php

declare(strict_types=1);

namespace Hypervel;

use Hyperf\Command\Concerns\Confirmable;
use Hyperf\Coroutine\Coroutine;
use Hyperf\ViewEngine\Compiler\CompilerInterface;
use Hypervel\View\CompilerFactory;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                CompilerInterface::class => CompilerFactory::class,
            ],
            'annotations' => [
                'scan' => [
                    'class_map' => [
                        Confirmable::class => __DIR__ . '/../class_map/Command/Concerns/Confirmable.php',
                        Coroutine::class => __DIR__ . '/../class_map/Hyperf/Coroutine/Coroutine.php',
                    ],
                ],
            ],
        ];
    }
}
