<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Cache\TagMode;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Doctor\Support\Details;
use Hypervel\Redis\RedisRequirements;
use Hypervel\Session\SessionManager;
use Hypervel\Support\Facades\Redis;
use Swoole\Coroutine\CanceledException;
use Throwable;

class RedisSupportsConfiguredFeatures extends Diagnostic
{
    public string $name = 'Redis supports configured features';

    public string $group = 'cache';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application is not using Redis-backed cache, queue, or session storage.',
            'unsupported' => Message::make(
                summary: 'The Redis client or server does not meet the configured feature requirements.',
                remediation: 'Install compatible phpredis and Redis/Valkey versions, or disable the features that require them.',
            )->link(Link::docs('redis', 'configuration')),
            'supported' => 'Redis meets the configured client and feature version requirements.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (ActiveDrivers::redisConnections() === []) {
            return $this->skip('not-configured');
        }

        $version = phpversion('redis') ?: '0';

        if (version_compare($version, RedisRequirements::MINIMUM_PHPREDIS_VERSION, '<')) {
            return $this->fail('unsupported')->withDetails(sprintf(
                'Redis requires phpredis >= %s (installed: %s).',
                RedisRequirements::MINIMUM_PHPREDIS_VERSION,
                $version,
            ));
        }

        $connections = $this->featureConnections();

        if ($connections === []) {
            return $this->pass('supported');
        }

        if (($error = RedisRequirements::hashFieldExpirationClientError($version)) !== null) {
            return $this->fail('unsupported')->withDetails($error);
        }

        $failures = [];

        foreach ($connections as $connection) {
            try {
                $error = RedisRequirements::hashFieldExpirationServerError(Redis::connection($connection)->info('server'));

                if ($error !== null) {
                    $failures[$connection] = $error;
                }
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $e) {
                $failures[$connection] = $e->getMessage();
            }
        }

        if ($failures !== []) {
            return $this->fail('unsupported')
                ->withDetails(Details::failures($failures));
        }

        return $this->pass('supported');
    }

    /**
     * Get data connections that need hash field expiration.
     *
     * @return list<string>
     */
    private function featureConnections(): array
    {
        $connections = [];
        $default = Configured::string('cache.default');

        foreach ($default === null ? [] : ActiveDrivers::cacheStores($default) as $store) {
            $configuration = config("cache.stores.{$store}");

            if (! is_array($configuration) || ($configuration['driver'] ?? null) !== 'redis') {
                continue;
            }

            $mode = $configuration['tag_mode'] ?? TagMode::All;
            $mode = $mode instanceof TagMode ? $mode : TagMode::fromConfig($mode);

            if ($mode === TagMode::Any) {
                $connections[] = $configuration['connection'] ?? 'default';
            }
        }

        if (config('session.driver') === 'redis' && config()->boolean('session.track_user_sessions')) {
            $connections[] = Configured::string('session.connection', SessionManager::DEFAULT_REDIS_CONNECTION);
        }

        return array_values(array_unique($connections));
    }
}
