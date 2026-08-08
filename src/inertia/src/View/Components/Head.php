<?php

declare(strict_types=1);

namespace Hypervel\Inertia\View\Components;

use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\View\Component;

class Head extends Component
{
    public ?Response $response;

    /**
     * Create a new Inertia head component.
     */
    public function __construct()
    {
        $this->response = InertiaState::current()->dispatchSsr();
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return <<<'blade'
@if($response)
{!! $response->head !!}
@else
{!! $slot !!}
@endif
blade;
    }
}
