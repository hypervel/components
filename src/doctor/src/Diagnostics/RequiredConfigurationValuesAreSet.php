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
use Psr\Log\LoggerInterface;
use UnitEnum;

use function Hypervel\Support\enum_value;

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
                summary: 'Some configuration values required by the active drivers are missing or invalid.',
                remediation: 'Check the listed settings and their environment variables in .env or the deployment environment.',
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
        $mailer = Configured::string('mail.default');

        return [
            ...$this->missingDefaultValues(),
            ...$this->missingBroadcastingValues(),
            ...$this->missingFilesystemValues(),
            ...$this->missingLoggingValues(),
            ...$this->missingQueueValues(),
            ...($mailer === null ? [] : $this->missingMailerValues($mailer)),
        ];
    }

    /**
     * Get default driver keys without a usable value, mapped to the feature that needs them.
     *
     * The managers read these keys with typed getters, so a blank or null
     * value fails at runtime instead of falling back to a default driver.
     *
     * @return array<string, string>
     */
    private function missingDefaultValues(): array
    {
        $defaults = [
            'broadcasting.default' => 'default broadcaster',
            'cache.default' => 'default cache store',
            'database.default' => 'default database connection',
            'filesystems.default' => 'default filesystem disk',
            'mail.default' => 'default mailer',
            'queue.default' => 'default queue connection',
        ];

        // @phpstan-ignore hypervel.untypedConfigRead (Null disables the session middleware; other malformed selectors must be reported.)
        if (config('session.driver') !== null) {
            $defaults['session.driver'] = 'session driver';
        }

        return array_filter(
            $defaults,
            static fn (string $key): bool => Configured::string($key) === null,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Get missing settings for the default broadcasting connection.
     *
     * @return array<string, string>
     */
    private function missingBroadcastingValues(): array
    {
        $connection = Configured::string('broadcasting.default');

        if ($connection === null || $connection === 'null') {
            return [];
        }

        $prefix = "broadcasting.connections.{$connection}";
        if (Configured::array($prefix) === null) {
            return [$prefix => 'default broadcaster'];
        }

        // The manager selects the driver from the connection record, even when the names match.
        $driver = Configured::string("{$prefix}.driver");
        if (blank($driver)) {
            return ["{$prefix}.driver" => 'default broadcaster'];
        }

        if ($driver === 'mercure') {
            return $this->missingMercureValues($prefix);
        }

        $keys = match ($driver) {
            'ably' => ['key'],
            'pusher', 'reverb' => ['app_id', 'key', 'secret'],
            default => [],
        };

        return $this->missingKeys($prefix, $keys, "{$driver} broadcaster");
    }

    /**
     * Get missing Mercure values using the factory's effective credential choices.
     *
     * @return array<string, string>
     */
    private function missingMercureValues(string $prefix): array
    {
        $keys = ["{$prefix}.url"];

        foreach (['publish', 'subscribe'] as $side) {
            // @phpstan-ignore hypervel.untypedConfigRead (Mercure chooses the effective credential by its truthiness.)
            $keys[] = config("{$prefix}.{$side}_secret") ? "{$prefix}.{$side}_secret" : "{$prefix}.secret";
        }

        $missing = [];

        foreach ($keys as $key) {
            // @phpstan-ignore hypervel.untypedConfigRead (Check missing credentials even when other settings are malformed.)
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
    private function missingFilesystemValues(): array
    {
        $disk = Configured::string('filesystems.default');

        if ($disk === null || Configured::string("filesystems.disks.{$disk}.driver") !== 's3') {
            return [];
        }

        return $this->missingKeys("filesystems.disks.{$disk}", ['bucket', 'region'], 's3 filesystem disk');
    }

    /**
     * Get configuration keys required by the active log channels.
     *
     * @return array<string, string>
     */
    private function missingLoggingValues(): array
    {
        // @phpstan-ignore hypervel.untypedConfigRead (Report malformed selectors and honor LogManager's explicit null channel in tests.)
        $default = config('logging.default');

        if ($default === null && app()->runningUnitTests()) {
            $default = 'null';
        }

        if (! is_string($default) || trim($default) === '') {
            return ['logging.default' => 'default log channel'];
        }

        return $this->missingLogChannelValues(trim($default));
    }

    /**
     * Get configuration keys required by a log channel.
     *
     * @param list<string> $ancestors
     * @return array<string, string>
     */
    private function missingLogChannelValues(string $channel, array $ancestors = []): array
    {
        $prefix = "logging.channels.{$channel}";
        $configuration = Configured::array($prefix);
        if ($configuration === null) {
            return [$prefix => 'log channel'];
        }

        $driver = $configuration['driver'] ?? null;
        if (! is_string($driver) || trim($driver) === '') {
            return ["{$prefix}.driver" => 'log channel'];
        }

        if ($driver === 'stack') {
            $ancestors[] = $channel;
            return $this->missingLogStackValues($prefix, $configuration['channels'] ?? null, $ancestors);
        }

        if ($driver === 'slack') {
            return $this->missingKeys($prefix, ['url'], 'slack log channel');
        }

        if ($driver === 'monolog' && Configured::string("{$prefix}.handler") === SyslogUdpHandler::class) {
            $with = Configured::array("{$prefix}.with") ?? [];
            $handlerWith = Configured::array("{$prefix}.handler_with") ?? [];
            $values = [];

            foreach (['host', 'port'] as $key) {
                $source = array_key_exists($key, $handlerWith) || ! array_key_exists($key, $with)
                    ? 'handler_with'
                    : 'with';
                $values[] = "{$source}.{$key}";
            }

            return $this->missingKeys($prefix, $values, "{$channel} log channel");
        }

        return [];
    }

    /**
     * Check the channels selected by a logging stack.
     *
     * @param list<string> $ancestors
     * @return array<string, string>
     */
    private function missingLogStackValues(string $prefix, mixed $channels, array $ancestors): array
    {
        if ($channels === null) {
            return ["{$prefix}.channels" => 'log stack'];
        }

        $missing = [];
        foreach (is_string($channels) ? explode(',', $channels) : collect($channels) as $child) {
            if ($child instanceof LoggerInterface) {
                continue;
            }

            $child = $child instanceof UnitEnum ? (string) enum_value($child) : $child;
            $name = is_string($child) ? trim($child) : '';

            // A null member also recurses into the default channel at the root of this stack.
            if ($name === '' || in_array($name, $ancestors, true)) {
                $missing["{$prefix}.channels"] = 'log stack';
                continue;
            }

            $missing += $this->missingLogChannelValues($name, $ancestors);
        }

        return $missing;
    }

    /**
     * Check the selected mailer and the configuration of its child transports.
     *
     * @param list<string> $ancestors
     * @return array<string, string>
     */
    private function missingMailerValues(string $mailer, array $ancestors = []): array
    {
        $configuration = ActiveDrivers::mailerConfiguration($mailer);
        $prefix = "mail.mailers.{$mailer}";
        $transport = $configuration['transport'] ?? null;
        if (! is_string($transport) || trim($transport) === '') {
            $key = Configured::array($prefix) === null ? $prefix : "{$prefix}.transport";
            return [$key => 'mailer'];
        }

        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $configuration['mailers'] ?? null;
            if (! is_array($children) || $children === []) {
                return ["{$prefix}.mailers" => "{$transport} mailer"];
            }

            $missing = [];
            $ancestors[] = $mailer;
            foreach ($children as $child) {
                if (! is_string($child) || trim($child) === '' || in_array($child, $ancestors, true)) {
                    $missing["{$prefix}.mailers"] = "{$transport} mailer";
                    continue;
                }

                $missing += $this->missingMailerValues($child, $ancestors);
            }

            return $missing;
        }

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
                // @phpstan-ignore hypervel.untypedConfigRead (Check missing credentials even when other settings are malformed.)
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
    private function missingQueueValues(): array
    {
        $connection = Configured::string('queue.default');

        if ($connection === null) {
            return [];
        }

        $values = [];

        foreach (ActiveDrivers::queueConnections($connection) as $name) {
            if ($name === 'null' || Configured::string("queue.connections.{$name}.driver") !== 'sqs'
                // @phpstan-ignore hypervel.untypedConfigRead (Inspect the configured overflow options even when their types are invalid.)
                || config("queue.connections.{$name}.overflow.enabled") !== true
                // @phpstan-ignore hypervel.untypedConfigRead (Inspect the configured overflow options even when their types are invalid.)
                || config("queue.connections.{$name}.overflow.store") === null) {
                continue;
            }

            $values = [...$values, ...$this->missingKeys("queue.connections.{$name}.overflow", ['store'], 'sqs queue overflow')];
        }

        return $values;
    }

    /**
     * Map missing configuration keys under a prefix to the feature that requires them.
     *
     * @param list<string> $keys
     * @return array<string, string>
     */
    private function missingKeys(string $prefix, array $keys, string $feature): array
    {
        $keys = array_map(static fn (string $key): string => "{$prefix}.{$key}", $keys);

        return array_fill_keys(Configured::missing($keys), $feature);
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
