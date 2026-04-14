<?php

declare(strict_types=1);

namespace Hypervel\Inertia\View\Components;

use Hypervel\Context\CoroutineContext;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\View\Component;

class Head extends Component
{
    public ?Response $response;

    public function __construct()
    {
        $state = CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);

        if (! $state->ssrDispatched) {
            $state->ssrDispatched = true;
            $state->ssrResponse = app(Gateway::class)->dispatch($state->page);
        }

        $this->response = $state->ssrResponse;
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
