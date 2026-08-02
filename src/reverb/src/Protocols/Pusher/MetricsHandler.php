<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Reverb\Servers\Hypervel\MetricsRequestPipeMessage;
use Hypervel\Reverb\Servers\Hypervel\MetricsResponsePipeMessage;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Str;
use RuntimeException;
use Swoole\Coroutine\Channel;
use Swoole\Server;
use Throwable;

class MetricsHandler
{
    use InteractsWithChannelInformation;

    /**
     * The metrics being gathered.
     *
     * @var array<string, PendingMetric>
     */
    protected array $metrics = [];

    /**
     * The waiters for metrics being gathered from sibling workers.
     *
     * @var array<string, Channel>
     */
    protected array $waiters = [];

    /**
     * Create an instance of the metrics handler.
     */
    public function __construct(
        protected ServerProviderManager $serverProviderManager,
        protected ChannelManager $channels,
        protected PubSubProvider $pubSubProvider,
        protected Server $server,
    ) {
    }

    /**
     * Gather the metrics for the given type.
     */
    public function gather(Application $application, string $type, array $options = []): array
    {
        $type = MetricType::from($type);
        $workerCount = (int) ($this->server->setting['worker_num'] ?? 1);
        $scaled = $this->serverProviderManager->subscribesToEvents();

        if (! $scaled && $workerCount <= 1) {
            return $this->getLocal($application, $type, $options);
        }

        $options = $this->distributedOptions($type, $options);
        $metric = new PendingMetric(
            Str::random(10),
            $application,
            $type,
            $options
        );

        return $scaled
            ? $this->gatherMetricsFromSubscribers($metric)
            : $this->gatherMetricsFromWorkers($metric, $workerCount);
    }

    /**
     * Get the metrics for the given type.
     */
    public function get(PendingMetric $metric): array
    {
        return $this->getLocal($metric->application(), $metric->type(), $metric->options());
    }

    /**
     * Get the local metrics for the given type.
     */
    protected function getLocal(Application $application, MetricType $type, array $options): array
    {
        return match ($type) {
            MetricType::Channel => $this->channel($application, $options),
            MetricType::Channels => $this->channels($application, $options),
            MetricType::Connections => $this->connections($application),
            MetricType::Presence => $this->presence($application, $options),
        };
    }

    /**
     * Get the channel for the given application.
     */
    protected function channel(Application $application, array $options): array
    {
        return $this->info(
            $application,
            $options['channel'],
            $options['info'] ?? '',
            $options['reverb_include_user_ids'] ?? false,
        );
    }

    /**
     * Get the channels for the given application.
     */
    protected function channels(Application $application, array $options): array
    {
        if ($options['channels'] ?? false) {
            return $this->infoForChannels(
                $application,
                $options['channels'],
                $options['info'] ?? '',
                $options['reverb_include_user_ids'] ?? false,
            );
        }

        $channels = collect($this->channels->for($application)->all());

        if ($filter = $options['filter'] ?? false) {
            $channels = $channels->filter(fn ($channel) => Str::startsWith($channel->name(), $filter));
        }

        $channels = $channels->filter(fn ($channel) => count($channel->connections()) > 0);

        return $this->infoForChannels(
            $application,
            $channels->all(),
            $options['info'] ?? '',
            $options['reverb_include_user_ids'] ?? false,
        );
    }

    /**
     * Get the connections for the given application.
     */
    protected function connections(Application $application): array
    {
        return ['count' => count($this->channels->for($application)->connections())];
    }

    /**
     * Get a local presence-channel snapshot.
     */
    protected function presence(Application $application, array $options): array
    {
        $channel = $this->channels->for($application)->find($options['channel']);

        if ($channel === null) {
            return [
                'exists' => false,
                'presence' => false,
                'users' => [],
            ];
        }

        $presence = $this->isPresenceChannel($channel);

        return [
            'exists' => true,
            'presence' => $presence,
            'users' => $presence
                ? collect($channel->connections())
                    ->map(fn ($connection) => $connection->data())
                    ->unique('user_id')
                    ->values()
                    ->all()
                : [],
        ];
    }

    /**
     * Gather metrics from all subscribers for the given type.
     *
     * Uses a Swoole coroutine channel with timeout instead of ReactPHP promises.
     */
    protected function gatherMetricsFromSubscribers(PendingMetric $metric): array
    {
        $waiter = $this->startGathering($metric);
        $listening = false;
        $exception = null;

        try {
            $this->pubSubProvider->on($metric->key(), function (array $payload) use ($metric) {
                $this->appendMetric($metric->key(), $payload['payload']);
            });
            $listening = true;

            $subscriberCount = $this->pubSubProvider->publish([
                'type' => 'metrics_request',
                'request_id' => $metric->key(),
                'app_id' => $metric->application()->id(),
                'metric_type' => $metric->type()->value,
                'options' => $metric->options(),
            ]);

            $metric->setSubscriberCount($subscriberCount);

            return $this->mergeSubscriberMetrics(
                $this->waitForMetric($metric, $waiter),
                $metric->type(),
            );
        } catch (Throwable $throwable) {
            $exception = $throwable;

            throw $throwable;
        } finally {
            $this->stopGathering($metric);

            if ($listening) {
                try {
                    $this->pubSubProvider->stopListening($metric->key());
                } catch (Throwable $throwable) {
                    if ($exception === null) {
                        throw $throwable;
                    }
                }
            }
        }
    }

    /**
     * Gather metrics from this worker and every sibling worker.
     */
    protected function gatherMetricsFromWorkers(PendingMetric $metric, int $workerCount): array
    {
        $waiter = $this->startGathering($metric);

        try {
            $metric->setSubscriberCount($workerCount);
            $metric->append($this->get($metric));
            $exception = null;

            $message = new MetricsRequestPipeMessage(
                $metric->key(),
                $metric->application()->id(),
                $metric->type()->value,
                $metric->options(),
            );

            for ($workerId = 0; $workerId < $workerCount; ++$workerId) {
                if ($workerId === $this->server->worker_id) {
                    continue;
                }

                try {
                    if (! $this->server->sendMessage($message, $workerId)) {
                        $exception ??= new RuntimeException(
                            "Unable to request Reverb metrics from worker [{$workerId}].",
                        );
                    }
                } catch (Throwable $throwable) {
                    $exception ??= $throwable;
                }
            }

            if ($exception !== null) {
                throw $exception;
            }

            return $this->mergeSubscriberMetrics(
                $this->waitForMetric($metric, $waiter),
                $metric->type(),
            );
        } finally {
            $this->stopGathering($metric);
        }
    }

    /**
     * Receive a metrics response from a sibling worker.
     */
    public function receive(MetricsResponsePipeMessage $message): void
    {
        $this->appendMetric($message->requestId, $message->payload);
    }

    /**
     * Merge the given metrics into a single result set.
     */
    protected function mergeSubscriberMetrics(array $metrics, MetricType $type): array
    {
        return match ($type) {
            MetricType::Connections => [
                'count' => array_sum(array_column($metrics, 'count')),
            ],
            MetricType::Channels => $this->mergeChannels($metrics),
            MetricType::Channel => $this->mergeChannel($metrics),
            MetricType::Presence => $this->mergePresence($metrics),
        };
    }

    /**
     * Merge multiple channel instances into a single set.
     */
    protected function mergeChannel(array $metrics): array
    {
        $merged = collect($metrics)
            ->reduce(function ($carry, $item) {
                collect($item)->each(fn ($value, $key) => $carry->put($key, match ($key) {
                    'occupied' => $carry->get($key, false) || $value,
                    'reverb_user_ids' => array_merge($carry->get($key, []), $value),
                    'user_count' => $carry->get($key, 0) + $value,
                    'subscription_count' => $carry->get($key, 0) + $value,
                    default => $value,
                }));

                return $carry;
            }, collect());

        if ($merged->has('reverb_user_ids')) {
            $userIds = collect($merged->pull('reverb_user_ids'))->unique()->values();
            $merged->put('user_count', $userIds->count());
        }

        return $merged->all();
    }

    /**
     * Merge multiple sets of channel instances into a single result set.
     */
    protected function mergeChannels(array $metrics): array
    {
        return collect($metrics)
            ->reduce(function ($carry, $item) {
                collect($item)->each(function ($data, $channel) use ($carry) {
                    $metrics = $carry->get($channel, []);
                    $metrics[] = $data;
                    $carry->put($channel, $metrics);
                });

                return $carry;
            }, collect())
            ->map(fn ($metrics) => $this->mergeChannel($metrics))
            ->all();
    }

    /**
     * Merge presence snapshots into one node-wide view.
     */
    protected function mergePresence(array $metrics): array
    {
        $merged = collect($metrics)->reduce(function (array $carry, array $metric): array {
            $carry['exists'] = $carry['exists'] || $metric['exists'];
            $carry['presence'] = $carry['presence'] || $metric['presence'];
            $carry['users'] = array_merge($carry['users'], $metric['users']);

            return $carry;
        }, [
            'exists' => false,
            'presence' => false,
            'users' => [],
        ]);

        $merged['users'] = collect($merged['users'])->unique('user_id')->values()->all();

        return $merged;
    }

    /**
     * Publish the metrics for the given type.
     *
     * Called by the pub/sub message handler when this node receives a metrics request.
     */
    public function publish(PendingMetric $metric): void
    {
        $this->pubSubProvider->publish([
            'type' => $metric->key(),
            'payload' => $this->get($metric),
        ]);
    }

    /**
     * Add distributed merge data when exact user counts were requested.
     */
    protected function distributedOptions(MetricType $type, array $options): array
    {
        if (! in_array($type, [MetricType::Channel, MetricType::Channels], true)) {
            return $options;
        }

        if (! in_array('user_count', explode(',', $options['info'] ?? ''), true)) {
            return $options;
        }

        $options['reverb_include_user_ids'] = true;

        return $options;
    }

    /**
     * Register a pending metric and its waiter.
     */
    protected function startGathering(PendingMetric $metric): Channel
    {
        $waiter = new Channel(1);

        $this->metrics[$metric->key()] = $metric;
        $this->waiters[$metric->key()] = $waiter;

        return $waiter;
    }

    /**
     * Append a response to a pending metric.
     */
    protected function appendMetric(string $key, array $payload): void
    {
        $metric = $this->metrics[$key] ?? null;
        $waiter = $this->waiters[$key] ?? null;

        if ($metric === null || $waiter === null) {
            return;
        }

        $metric->append($payload);

        if ($metric->resolvable()) {
            $waiter->push($metric->resolve());
        }
    }

    /**
     * Wait for a pending metric or return every response received before the deadline.
     */
    protected function waitForMetric(PendingMetric $metric, Channel $waiter): array
    {
        if ($metric->resolvable()) {
            return $metric->resolve();
        }

        $result = $waiter->pop(10.0);

        if ($result !== false) {
            return $result;
        }

        $received = count($metric->resolve());

        Log::warning('Timed out while gathering Reverb metrics.', [
            'type' => $metric->type()->value,
            'received' => $received,
            'expected' => $received + $metric->missingResponseCount(),
        ]);

        return $metric->resolve();
    }

    /**
     * Remove a pending metric and its waiter.
     */
    protected function stopGathering(PendingMetric $metric): void
    {
        unset($this->metrics[$metric->key()], $this->waiters[$metric->key()]);
    }
}
