<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\Fixtures;

use Hypervel\Inertia\Ssr\Gateway;
use Hypervel\Inertia\Ssr\Response;
use Hypervel\Support\Facades\Config;

class FakeGateway implements Gateway
{
    /**
     * Tracks the number of times the 'dispatch' method was called.
     */
    public int $times = 0;

    /**
     * Dispatch the Inertia page to the Server Side Rendering engine.
     */
    public function dispatch(array $page): ?Response
    {
        ++$this->times;

        if (! Config::get('inertia.ssr.enabled', false)) {
            return null;
        }

        return new Response(
            "<meta charset=\"UTF-8\" />\n<title inertia>Example SSR Title</title>\n",
            '<p>This is some example SSR content</p>'
        );
    }
}
