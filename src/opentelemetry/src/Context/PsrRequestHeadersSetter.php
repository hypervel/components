<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use InvalidArgumentException;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Psr\Http\Message\RequestInterface;

class PsrRequestHeadersSetter implements PropagationSetterInterface
{
    /**
     * Set a request header on an immutable PSR-7 carrier.
     * @param mixed $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        if (! $carrier instanceof RequestInterface) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP request carrier type [%s].',
                get_debug_type($carrier),
            ));
        }

        $carrier = $carrier->withHeader($key, $value);
    }
}
