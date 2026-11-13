<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\RequestDependentBindings\LocalRequest;

use Hypervel\Container\Attributes\BindWhen;
use Hypervel\Tests\PHPStan\Rules\RequestDependentBindings\Implementation;

use function Hypervel\Tests\PHPStan\Rules\RequestDependentBindings\onAdminPath;

#[BindWhen(Implementation::class, onAdminPath(...))]
interface UsesHelperFromAnotherNamespace
{
}

function request(): bool
{
    return true;
}

function onLocalFlag(): bool
{
    return request();
}
