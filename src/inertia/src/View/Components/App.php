<?php

declare(strict_types=1);

namespace Hypervel\Inertia\View\Components;

use Hypervel\Context\CoroutineContext;
use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\View\Component;

class App extends Component
{
    public ?Response $response;

    public string $pageJson;

    public function __construct(
        public string $id = 'app',
    ) {
        $state = CoroutineContext::getOrSet(InertiaState::CONTEXT_KEY, fn () => new InertiaState);

        if (! $state->ssrDispatched) {
            $state->ssrDispatched = true;
            $state->ssrResponse = app(Gateway::class)->dispatch($state->page);
        }

        $this->response = $state->ssrResponse;
        $this->pageJson = (string) json_encode($state->page);
    }

    /**
     * Render the component.
     */
    public function render(): string
    {
        return <<<'blade'
@if($response)
{!! $response->body !!}
@else
<script data-page="{{ $id }}" type="application/json">{!! $pageJson !!}</script><div id="{{ $id }}"></div>
@endif
blade;
    }
}
