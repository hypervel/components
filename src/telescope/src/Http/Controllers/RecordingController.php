<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Contracts\Cache\Repository;

class RecordingController
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected Repository $cache
    ) {
    }

    /**
     * Toggle recording.
     */
    public function toggle(): array
    {
        if ($this->cache->get('telescope:pause-recording')) {
            $this->cache->forget('telescope:pause-recording');
        } else {
            $this->cache->put('telescope:pause-recording', true, now()->addDays(30));
        }

        return [
            'success' => true,
        ];
    }
}
