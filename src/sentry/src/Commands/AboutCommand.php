<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Commands;

use Hypervel\Console\Command;
use Hypervel\Sentry\Version;
use Hypervel\Support\Collection;
use Sentry\Client;
use Sentry\State\HubInterface;

class AboutCommand extends Command
{
    protected ?string $name = 'sentry:about';

    protected string $description = 'Show what Sentry SDK is used in this project and if it is enabled';

    public function handle(): void
    {
        $hub = $this->app->make(HubInterface::class);
        $options = $this->getSentryOptions($hub);
        $this->table(
            ['Option', 'Value'],
            Collection::make($options)->map(fn ($value, $key) => [$key, $value])->toArray()
        );
    }

    protected function getSentryOptions(HubInterface $hub): array
    {
        $client = $hub->getClient();

        if ($client === null) {
            return [
                'Enabled' => '<fg=red;options=bold>NOT CONFIGURED</>',
                'Hypervel SDK Version' => Version::SDK_VERSION,
                'PHP SDK Version' => Client::SDK_VERSION,
            ];
        }

        $options = $client->getOptions();

        // Note: order is not important since Hypervel orders these alphabetically
        return [
            'Enabled' => $options->getDsn() ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>MISSING DSN</>',
            'Environment' => $options->getEnvironment() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Hypervel SDK Version' => Version::SDK_VERSION,
            'PHP SDK Version' => Client::SDK_VERSION,
            'Release' => $options->getRelease() ?: '<fg=yellow;options=bold>NOT SET</>',
            'Sample Rate Errors' => $this->formatSampleRate($options->getSampleRate()),
            'Sample Rate Performance Monitoring' => $this->formatSampleRate(
                $options->getTracesSampleRate(),
                $options->getTracesSampler() !== null
            ),
            'Sample Rate Profiling' => $this->formatSampleRate($options->getProfilesSampleRate()),
            'Send Default PII' => $options->shouldSendDefaultPii(
            ) ? '<fg=green;options=bold>ENABLED</>' : '<fg=yellow;options=bold>DISABLED</>',
        ];
    }

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
