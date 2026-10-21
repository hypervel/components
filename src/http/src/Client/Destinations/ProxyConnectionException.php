<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Destinations;

use Hypervel\Http\Client\ConnectionException;

/**
 * The approved proxy could not be resolved or reached.
 *
 * The failure belongs to the caller's egress path rather than the destination,
 * which matters to callers that track the health of each destination.
 */
class ProxyConnectionException extends ConnectionException
{
}
