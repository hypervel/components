<?php

declare(strict_types=1);

namespace Hypervel;

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
        ];
    }
}
