<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\Fixtures;

use Hypervel\Routing\Controller;

class ApiResourceTaskController extends Controller
{
    /**
     * Display the resource listing.
     */
    public function index(): string
    {
        return 'I`m index tasks';
    }

    /**
     * Store the resource.
     */
    public function store(): string
    {
        return 'I`m store tasks';
    }

    /**
     * Display the resource.
     */
    public function show(): string
    {
        return 'I`m show tasks';
    }

    /**
     * Update the resource.
     */
    public function update(): string
    {
        return 'I`m update tasks';
    }

    /**
     * Delete the resource.
     */
    public function destroy(): string
    {
        return 'I`m destroy tasks';
    }
}
