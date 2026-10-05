<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Traits\Plugins;

use GuzzleHttp\RequestOptions;
use Hypervel\Saloon\Http\PendingRequest;

trait HasTimeout
{
    /**
     * Boot HasTimeout plugin.
     */
    public function bootHasTimeout(PendingRequest $pendingRequest): void
    {
        // Plugins boot after the connector and request options are merged, so a declared timeout only fills an
        // option that neither this class nor the request sets. A null timeout leaves the existing value in effect.
        $options = $this->options() + $pendingRequest->request()->options();
        $timeouts = [
            RequestOptions::CONNECT_TIMEOUT => $this->getConnectTimeout(),
            RequestOptions::TIMEOUT => $this->getRequestTimeout(),
        ];

        foreach ($timeouts as $option => $timeout) {
            if ($timeout !== null && ! array_key_exists($option, $options)) {
                $pendingRequest->withOptions([$option => $timeout]);
            }
        }
    }

    /**
     * Get the request connection timeout.
     */
    public function getConnectTimeout(): ?float
    {
        return property_exists($this, 'connectTimeout') ? $this->connectTimeout : null;
    }

    /**
     * Get the request timeout.
     */
    public function getRequestTimeout(): ?float
    {
        return property_exists($this, 'requestTimeout') ? $this->requestTimeout : null;
    }
}
