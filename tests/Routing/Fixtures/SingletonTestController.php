<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\Fixtures;

use Hypervel\Routing\Controller;

class SingletonTestController extends Controller
{
    /**
     * Display the resource.
     */
    public function show(): string
    {
        return 'singleton show';
    }

    /**
     * Display the resource editing form.
     */
    public function edit(): string
    {
        return 'singleton edit';
    }

    /**
     * Update the resource.
     */
    public function update(): string
    {
        return 'singleton update';
    }

    /**
     * Delete the resource.
     */
    public function destroy(): string
    {
        return 'singleton destroy';
    }
}
