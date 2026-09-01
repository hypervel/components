<?php

declare(strict_types=1);

namespace Hypervel\OpenTelemetry\Context;

use InvalidArgumentException;
use OpenTelemetry\Context\Propagation\ExtendedPropagationGetterInterface;
use Symfony\Component\HttpFoundation\HeaderBag;

class HeaderBagGetter implements ExtendedPropagationGetterInterface
{
    /**
     * Return every header name in the carrier.
     *
     * @param mixed $carrier
     * @return list<string>
     */
    public function keys($carrier): array
    {
        return $this->headerBag($carrier)->keys();
    }

    /**
     * Return the first value for a header.
     * @param mixed $carrier
     */
    public function get($carrier, string $key): ?string
    {
        return $this->headerBag($carrier)->get($key);
    }

    /**
     * Return every value for a header.
     *
     * @param mixed $carrier
     * @return list<string>
     */
    public function getAll($carrier, string $key): array
    {
        return $this->headerBag($carrier)->all($key);
    }

    /**
     * Return the supported header carrier.
     */
    protected function headerBag(mixed $carrier): HeaderBag
    {
        if (! $carrier instanceof HeaderBag) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported HTTP header carrier type [%s].',
                get_debug_type($carrier),
            ));
        }

        return $carrier;
    }
}
