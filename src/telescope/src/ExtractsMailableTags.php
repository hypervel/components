<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hypervel\Mail\Mailable;
use Hypervel\Queue\Contracts\ShouldQueue;

trait ExtractsMailableTags
{
    /**
     * Register a callback to extract mailable tags.
     */
    protected static function registerMailableTagExtractor()
    {
        $existingCallback = Mailable::$viewDataCallback;

        Mailable::buildViewDataUsing(function ($mailable) use ($existingCallback) {
            $existingData = $existingCallback ? call_user_func($existingCallback, $mailable) : [];

            return array_merge($existingData, [
                '__telescope' => ExtractTags::from($mailable),
                '__telescope_mailable' => get_class($mailable),
                '__telescope_queued' => in_array(ShouldQueue::class, class_implements($mailable)),
            ]);
        });
    }
}
