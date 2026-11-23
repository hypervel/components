<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Doctor\Support\Details;
use Monolog\Handler\SyslogUdpHandler;

class RequiredConfigurationValuesAreSet extends Diagnostic
{
    public string $name = 'Config values are set';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'set' => 'Every configuration value required by the active drivers is set.',
            'missing' => Message::make(
                summary: 'Some configuration values required by the active drivers are not set.',
                remediation: 'Set the missing values, typically by defining their environment variables in .env or the deployment environment.',
            )->link(Link::docs('configuration', 'environment-configuration')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $missing = $this->missingValues();

        if ($missing === []) {
            return $this->pass('set');
        }

        return $this->fail('missing')
            ->withDetails($this->formatMissingValues($missing));
    }

    /**
     * Get required configuration keys without a value, mapped to the feature that needs them.
     *
     * @return array<string, string>
     */
    private function missingValues(): array
    {
        $required = $this->requiredValues();

        return [
            ...array_intersect_key($required, array_flip(Configured::missing(array_keys($required)))),
            ...$this->missingMercureValues(),
            ...$this->missingMailValues(),
        ];
    }

    /**
     * Get configuration keys required by the active drivers, mapped to the feature that needs them.
     *
     * @return array<string, string>
     */
    private function requiredValues(): array
    {
        return [
            ...$this->broadcastingValues(),
            ...$this->filesystemValues(),
            ...$this->loggingValues(),
            ...$this->queueValues(),
        ];
    }

    /**
     * Get configuration keys required by the default broadcasting connection.
     *
     * @return array<string, string>
     */
    private function broadcastingValues(): array
    {
        $connection = Configured::string('broadcasting.default', 'null');
        $driver = Configured::string("broadcasting.connections.{$connection}.driver", $connection);

        $keys = match ($driver) {
            'ably' => ['key'],
            'pusher', 'reverb' => ['app_id', 'key', 'secret'],
            default => [],
        };

        return $this->values("broadcasting.connections.{$connection}", $keys, "{$driver} broadcaster");
    }

    /**
     * Get missing Mercure values using the factory's effective credential choices.
     *
     * @return array<string, string>
     */
    private function missingMercureValues(): array
    {
        $connection = Configured::string('broadcasting.default', 'null');
        $prefix = "broadcasting.connections.{$connection}";

        if (Configured::string("{$prefix}.driver", $connection) !== 'mercure') {
            return [];
        }

        $keys = ["{$prefix}.url"];

        foreach (['publish', 'subscribe'] as $side) {
            $keys[] = config("{$prefix}.{$side}_secret") ? "{$prefix}.{$side}_secret" : "{$prefix}.secret";
        }

        $missing = [];

        foreach ($keys as $key) {
            $value = config($key);

            if (! $value || blank($value)) {
                $missing[$key] = 'mercure broadcaster';
            }
        }

        return $missing;
    }

    /**
     * Get configuration keys required by the default filesystem disk.
     *
     * @return array<string, string>
     */
    private function filesystemValues(): array
    {
        $disk = Configured::string('filesystems.default', 'local');

        if (Configured::string("filesystems.disks.{$disk}.driver", $disk) !== 's3') {
            return [];
        }

        return $this->values("filesystems.disks.{$disk}", ['bucket', 'region'], 's3 filesystem disk');
    }

    /**
     * Get configuration keys required by the active log channels.
     *
     * @return array<string, string>
     */
    private function loggingValues(): array
    {
        $values = [];

        foreach (ActiveDrivers::logChannels(Configured::string('logging.default', 'stack')) as $channel) {
            $values = [...$values, ...$this->logChannelValues($channel)];
        }

        return $values;
    }

    /**
     * Get configuration keys required by a log channel.
     *
     * @return array<string, string>
     */
    private function logChannelValues(string $channel): array
    {
        $driver = Configured::string("logging.channels.{$channel}.driver", $channel);

        if ($driver === 'slack') {
            return $this->values("logging.channels.{$channel}", ['url'], 'slack log channel');
        }

        if ($driver === 'monolog' && config("logging.channels.{$channel}.handler") === SyslogUdpHandler::class) {
            $prefix = "logging.channels.{$channel}";
            $with = config("{$prefix}.with") ?? [];
            $handlerWith = config("{$prefix}.handler_with") ?? [];
            $values = [];

            foreach (['host', 'port'] as $key) {
                $source = array_key_exists($key, $handlerWith) || ! array_key_exists($key, $with)
                    ? 'handler_with'
                    : 'with';
                $values["{$prefix}.{$source}.{$key}"] = "{$channel} log channel";
            }

            return $values;
        }

        return [];
    }

    /**
     * Get missing values from the active mailers' effective configuration.
     *
     * @return array<string, string>
     */
    private function missingMailValues(): array
    {
        $values = [];

        foreach (ActiveDrivers::mailers(Configured::string('mail.default', 'log')) as $mailer) {
            $values = [...$values, ...$this->missingMailerValues($mailer)];
        }

        return $values;
    }

    /**
     * Get missing values using the mail transport factory's credential choices.
     *
     * @return array<string, string>
     */
    private function missingMailerValues(string $mailer): array
    {
        $configuration = ActiveDrivers::mailerConfiguration($mailer);
        $transport = $configuration['transport'] ?? $mailer;
        $prefix = "mail.mailers.{$mailer}";
        $keys = match ($transport) {
            'smtp' => ['host'],
            'sendmail' => ['path'],
            'postmark', 'resend' => ['key'],
            'cloudflare' => ['account_id', 'key'],
            'mailgun' => ['secret', 'domain'],
            default => [],
        };

        if ($transport === 'mailgun' && ! isset($configuration['secret'])) {
            $configuration = config()->array('services.mailgun', []);
            $prefix = 'services.mailgun';
        }

        $missing = [];

        foreach ($keys as $key) {
            $value = $configuration[$key] ?? null;
            $location = "{$prefix}.{$key}";

            if ($value === null && in_array($transport, ['postmark', 'resend', 'cloudflare'], true)) {
                $location = "services.{$transport}.{$key}";
                $value = config($location);
            }

            if (blank($value)) {
                $missing[$location] = "{$transport} mailer";
            }
        }

        return $missing;
    }

    /**
     * Get configuration keys required by the active queue connections.
     *
     * @return array<string, string>
     */
    private function queueValues(): array
    {
        $connection = Configured::string('queue.default', 'database');
        $values = [];

        foreach (ActiveDrivers::queueConnections($connection) as $name) {
            if (Configured::string("queue.connections.{$name}.driver", $name) !== 'sqs'
                || config("queue.connections.{$name}.overflow.enabled") !== true
                || config("queue.connections.{$name}.overflow.store") === null) {
                continue;
            }

            $values = [...$values, ...$this->values("queue.connections.{$name}.overflow", ['store'], 'sqs queue overflow')];
        }

        return $values;
    }

    /**
     * Map configuration keys under a prefix to the feature that requires them.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function values(string $prefix, array $keys, string $feature): array
    {
        $values = [];

        foreach ($keys as $key) {
            $values["{$prefix}.{$key}"] = $feature;
        }

        return $values;
    }

    /**
     * Format missing configuration values.
     *
     * @param array<string, string> $missing
     */
    private function formatMissingValues(array $missing): string
    {
        return Details::bullets(array_map(
            static fn (string $key, string $feature): string => sprintf('%s (%s)', $key, $feature),
            array_keys($missing),
            $missing,
        ));
    }
}
