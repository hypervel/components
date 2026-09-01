<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use Hypervel\Grpc\Metadata;
use InvalidArgumentException;
use OpenTelemetry\Context\Propagation\PropagationSetterInterface;

class GrpcMetadataSetter implements PropagationSetterInterface
{
    /**
     * Set a propagation field on immutable gRPC metadata.
     *
     * @param mixed $carrier
     */
    public function set(&$carrier, string $key, string $value): void
    {
        if (! $carrier instanceof Metadata) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported gRPC metadata carrier type [%s].',
                get_debug_type($carrier),
            ));
        }

        $carrier = $carrier->without($key)->with($key, $value);
    }
}
