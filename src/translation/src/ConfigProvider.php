<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Contracts\Translation\Loader as LoaderContract;
use Hypervel\Contracts\Translation\Translator as TranslatorContract;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                LoaderContract::class => LoaderFactory::class,
                TranslatorContract::class => TranslatorFactory::class,
            ],
        ];
    }
}
