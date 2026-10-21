<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Console;

use Hypervel\Sentry\Version;
use Sentry\Client;
use Sentry\Options;
use Sentry\State\HubInterface;

class AboutCommandIntegration
{
    /**
     * Get the Sentry details for the `about` command.
     */
    public function __invoke(HubInterface $hub): array
    {
        $client = $hub->getClient();

        if ($client === null) {
            return [
                'Enabled' => '<fg=red;options=bold>NOT CONFIGURED</>',
                'Hypervel SDK Version' => Version::getSdkVersion(),
                'PHP SDK Version' => Client::SDK_VERSION,
            ];
        }

        $options = $client->getOptions();

        // Note: order is not important since Hypervel orders these alphabetically
        return [
            'Data Collection' => $options->getDataCollection() !== null ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>NOT SET</>',
            'Enabled' => $options->getDsn() ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>MISSING DSN</>',
            'Environment' => $options->getEnvironment() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Hypervel SDK Version' => Version::getSdkVersion(),
            'PHP SDK Version' => Client::SDK_VERSION,
            'Release' => $options->getRelease() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Sample Rate Errors' => $this->formatSampleRate($options->getSampleRate()),
            'Sample Rate Performance Monitoring' => $this->formatSampleRate($options->getTracesSampleRate(), $options->getTracesSampler() !== null),
            'Sample Rate Profiling' => $this->formatSampleRate($options->getProfilesSampleRate(), $options->getProfilesSampler() !== null),
            'Send Default PII' => $this->formatSendDefaultPii($options),
        ];
    }

    /**
     * Format the `send_default_pii` status.
     */
    private function formatSendDefaultPii(Options $options): string
    {
        // The data collection options replace `send_default_pii`
        if ($options->getDataCollection() !== null) {
            return '<fg=yellow;options=bold>IGNORED</>';
        }

        return $options->shouldSendDefaultPii() ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>';
    }

    /**
     * Format a sample rate for display.
     */
    private function formatSampleRate(?float $sampleRate, bool $hasSamplerCallback = false): string
    {
        if ($hasSamplerCallback) {
            return '<fg=green;options=bold>CUSTOM SAMPLER</>';
        }

        if ($sampleRate === null) {
            return '<fg=yellow;options=bold>NOT SET</>';
        }

        return number_format($sampleRate * 100) . '%';
    }
}
