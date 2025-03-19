<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Cache\Contracts\Factory as CacheFactory;

class RecordingController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected CacheFactory $cache
    ) {
    }

    /**
     * Toggle recording.
     */
    public function toggle(): array
    {
        /* @phpstan-ignore-next-line */
        if ($this->cache->get('telescope:pause-recording')) {
            /* @phpstan-ignore-next-line */
            $this->cache->forget('telescope:pause-recording');
        } else {
            /* @phpstan-ignore-next-line */
            $this->cache->put('telescope:pause-recording', true, now()->addDays(30));
        }

        return [
            'success' => true,
        ];
    }
}
