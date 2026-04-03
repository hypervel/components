<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Routing\Fixtures;

use Hypervel\Routing\Controller;

class ApiResourceTestController extends Controller
{
    public function index()
    {
        return 'I`m index';
    }

    public function store()
    {
        return 'I`m store';
    }

    public function show()
    {
        return 'I`m show';
    }

    public function update()
    {
        return 'I`m update';
    }

    public function destroy()
    {
        return 'I`m destroy';
    }
}
