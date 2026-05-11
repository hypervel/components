<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Controllers;

use Hypervel\Tests\Wayfinder\Fixtures\Models\User;

class ModelBindingController
{
    public function show(User $user)
    {
    }
}
