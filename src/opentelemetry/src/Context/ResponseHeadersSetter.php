<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use InvalidArgumentException;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;
use Symfony\Component\HttpFoundation\Response;

class ResponseHeadersSetter implements PropagationSetterInterface
{
    /**
     * Set a response header.
     * @param mixed $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        if (! $carrier instanceof Response) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP response carrier type [%s].',
                get_debug_type($carrier),
            ));
        }

        $carrier->headers->set($key, $value);
    }
}
