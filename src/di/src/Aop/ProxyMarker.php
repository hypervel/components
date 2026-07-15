<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

/**
 * Marks generated AOP proxies without injecting callable behavior.
 *
 * The trait is intentionally empty so it composes safely when a class uses
 * multiple proxied traits. Its identity is discovered recursively in tests.
 */
trait ProxyMarker
{
}
