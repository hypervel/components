<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\Fixtures;

use Hypervel\Routing\Controller;

class NestedSingletonTestController extends Controller
{
    /**
     * Display the resource.
     */
    public function show(string $video): string
    {
        return "singleton show for {$video}";
    }

    /**
     * Display the resource editing form.
     */
    public function edit(string $video): string
    {
        return "singleton edit for {$video}";
    }

    /**
     * Update the resource.
     */
    public function update(string $video): string
    {
        return "singleton update for {$video}";
    }

    /**
     * Delete the resource.
     */
    public function destroy(string $video): string
    {
        return "singleton destroy for {$video}";
    }
}
