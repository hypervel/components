<?php

declare(strict_types=1);

namespace Hypervel\Translation;

use Hypervel\Translation\Contracts\Loader as LoaderContract;
use Hypervel\Translation\Contracts\Translator as TranslatorContract;

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
