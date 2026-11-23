<?php

namespace Hypervel\Tests\PHPStan\Rules\Data\NamespacedTranslationFunction;

function trans(string $key): string
{
    return $key;
}

function __(string $key): string
{
    return $key;
}

class NamespacedTranslationFunctionController
{
    public function index(): void
    {
        trans('package::lookups.missing');
        __('package::lookups.missing');
        \trans('package::lookups.missing');
    }
}
