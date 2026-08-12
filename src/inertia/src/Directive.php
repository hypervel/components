<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

class Directive
{
    /**
     * Compile the "@inertia" Blade directive. This directive renders the
     * Inertia root element with the page data, handling both client-side
     * rendering and SSR fallback scenarios.
     */
    public static function compile(string $expression = ''): string
    {
        $id = trim(trim($expression), "\\'\"");
        $id = $id === '' ? 'app' : $id;

        $template = '<?php
            $__inertiaState = \Hypervel\Inertia\InertiaState::current();
            $__inertiaState->page = $page;
            $__inertiaSsrResponse = $__inertiaState->dispatchSsr();

            if ($__inertiaSsrResponse) {
                echo $__inertiaSsrResponse->body;
            } else {
                ?><script data-page="' . $id . '" type="application/json">{!! json_encode($page, JSON_THROW_ON_ERROR) !!}</script><div id="' . $id . '"></div><?php
            }
        ?>';

        return implode(' ', array_map('trim', explode("\n", $template)));
    }

    /**
     * Compile the "@inertiaHead" Blade directive. This directive renders the
     * head content for SSR responses, including meta tags, title, and other
     * head elements from the server-side render.
     */
    public static function compileHead(string $expression = ''): string
    {
        $template = '<?php
            $__inertiaState = \Hypervel\Inertia\InertiaState::current();
            $__inertiaState->page = $page;
            $__inertiaSsrResponse = $__inertiaState->dispatchSsr();

            if ($__inertiaSsrResponse) {
                echo $__inertiaSsrResponse->head;
            }
        ?>';

        return implode(' ', array_map('trim', explode("\n", $template)));
    }
}
