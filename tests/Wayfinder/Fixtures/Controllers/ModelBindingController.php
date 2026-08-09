<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Controllers;

use Hypervel\Tests\Wayfinder\Fixtures\Models\User;

class ModelBindingController
{
    public function active(User $user): void
    {
    }

    public function price(User $user): void
    {
    }

    public function reference(User $user): void
    {
    }

    public function show(User $user): void
    {
    }

    public function optional(?User $user = null, ?string $filter = null): void
    {
    }
}
