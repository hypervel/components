<?php

declare(strict_types=1);

namespace Hypervel\Inertia\View\Components;

use Hypervel\Inertia\InertiaState;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\View\Component;

class App extends Component
{
    public ?Response $response;

    public string $pageJson;

    /**
     * Create a new Inertia application component.
     */
    public function __construct(
        public string $id = 'app',
    ) {
        $state = InertiaState::current();

        $this->response = $state->dispatchSsr();
        $this->pageJson = $this->response === null
            ? json_encode($state->page, JSON_THROW_ON_ERROR)
            : '';
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
