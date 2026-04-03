<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\Fixtures;

use Hypervel\Routing\Controller;

class SingletonTestController extends Controller
{
    public function show()
    {
        return 'singleton show';
    }

    public function edit()
    {
        return 'singleton edit';
    }

    public function update()
    {
        return 'singleton update';
    }

    public function destroy()
    {
        return 'singleton destroy';
    }
}
