<?php

declare(strict_types=1);

namespace Hypervel\Support;

/**
 * Marks that an encoder is registered with EncodedHtmlString.
 *
 * Markers count themselves while they are alive, including copies held by forked coroutine
 * contexts, so e() can skip the encoder lookup on Blade's hot path when no encoder exists.
 *
 * @internal
 */
final class HtmlEncoderMarker
{
    /**
     * The number of live markers.
     */
    public static int $live = 0;

    /**
     * Create a new HTML encoder marker instance.
     */
    public function __construct()
    {
        ++self::$live;
    }

    /**
     * Release the marker.
     */
    public function __destruct()
    {
        --self::$live;
    }
}
