<?php

namespace Hypervel\Tests\PHPStan\Rules\Data\NamespacedViewFunction;

function view(string $name): string
{
    return $name;
}

class NamespacedViewFunctionController
{
    public function index(): string
    {
        return view('unused');
    }
}
