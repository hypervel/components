<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\Fixtures;

use Hypervel\Routing\Controller;

class ApiResourceTaskController extends Controller
{
    public function index()
    {
        return 'I`m index tasks';
    }

    public function store()
    {
        return 'I`m store tasks';
    }

    public function show()
    {
        return 'I`m show tasks';
    }

    public function update()
    {
        return 'I`m update tasks';
    }

    public function destroy()
    {
        return 'I`m destroy tasks';
    }
}
