<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Protocols\Pusher\Concerns\InteractsWithChannelInformation;
use Hypervel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Hypervel\Reverb\ServerProviderManager;
use Hypervel\Reverb\Servers\Hypervel\Contracts\PubSubProvider;
use Hypervel\Support\Str;
use Swoole\Coroutine\Channel;

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
     * Create an instance of the metrics handler.
     */
    public function __construct(
        protected ServerProviderManager $serverProviderManager,
        protected ChannelManager $channels,
        protected PubSubProvider $pubSubProvider,
    ) {
    }

    /**
     * Gather the metrics for the given type.
     */
    public function gather(Application $application, string $type, array $options = []): array
    {
        $metric = new PendingMetric(
            Str::random(10),
            $application,
            MetricType::from($type),
            $options
        );

        return $this->serverProviderManager->subscribesToEvents()
            ? $this->gatherMetricsFromSubscribers($metric)
            : $this->get($metric);
    }

    /**
     * Get the metrics for the given type.
     */
    public function get(PendingMetric $metric): array
    {
        return match ($metric->type()) {
            MetricType::Channel => $this->channel($metric),
            MetricType::Channels => $this->channels($metric),
            MetricType::ChannelUsers => $this->channelUsers($metric),
            MetricType::Connections => $this->connections($metric),
        };
    }

    /**
     * Get the channel for the given application.
     */
    protected function channel(PendingMetric $metric): array
    {
        return $this->info($metric->application(), $metric->option('channel'), $metric->option('info', ''));
    }

    /**
     * Get the channels for the given application.
     */
    protected function channels(PendingMetric $metric): array
    {
        if ($metric->option('channels')) {
            return $this->infoForChannels($metric->application(), $metric->option('channels'), $metric->option('info', ''));
        }

        $channels = collect($this->channels->for($metric->application())->all());

        if ($filter = $metric->option('filter', false)) {
            $channels = $channels->filter(fn ($channel) => Str::startsWith($channel->name(), $filter));
        }

        $channels = $channels->filter(fn ($channel) => count($channel->connections()) > 0);

        return $this->infoForChannels(
            $metric->application(),
            $channels->all(),
            $metric->option('info', '')
        );
    }

    /**
     * Get the channel users for the given application.
     */
    protected function channelUsers(PendingMetric $metric): array
    {
        $channel = $this->channels->for($metric->application())->find($metric->option('channel'));

        if (! $channel) {
            return [];
        }

        return collect($channel->connections())
            ->map(fn ($connection) => $connection->data())
            ->unique('user_id')
            ->map(fn ($data) => ['id' => $data['user_id']])
            ->values()
            ->all();
    }

    /**
     * Get the connections for the given application.
     */
    protected function connections(PendingMetric $metric): array
    {
        return $this->channels->for($metric->application())->connections();
    }

    /**
     * Gather metrics from all subscribers for the given type.
     *
     * Uses a Swoole coroutine channel with timeout instead of ReactPHP promises.
     */
    protected function gatherMetricsFromSubscribers(PendingMetric $metric): array
    {
        $this->metrics[$metric->key()] = $metric;
        $channel = new Channel(1);

        $this->pubSubProvider->on($metric->key(), function (array $payload) use ($metric, $channel) {
            $pending = $this->metrics[$metric->key()];
            $pending->append($payload['payload']);

            if ($pending->resolvable()) {
                $channel->push($pending->resolve());
            }
        });

        // Publish uses scalar payloads (Decision 17)
        $subscriberCount = $this->pubSubProvider->publish([
            'type' => 'metrics_request',
            'request_id' => $metric->key(),
            'app_id' => $metric->application()->id(),
            'metric_type' => $metric->type()->value,
            'options' => $metric->options(),
        ]);

        $metric->setSubscriberCount($subscriberCount);

        // Fix race condition (Decision 16e): check if already resolvable
        // after setting subscriber count, before blocking on pop()
        if ($metric->resolvable()) {
            $this->stopListening($metric);

            return $this->mergeSubscriberMetrics($metric->resolve(), $metric->type());
        }

        $result = $channel->pop(10.0);

        $fallback = $metric->resolve();

        $this->stopListening($metric);

        return $result !== false
            ? $this->mergeSubscriberMetrics($result, $metric->type())
            : $this->mergeSubscriberMetrics($fallback, $metric->type());
    }

    /**
     * Merge the given metrics into a single result set.
     */
    protected function mergeSubscriberMetrics(array $metrics, MetricType $type): array
    {
        return match ($type) {
            MetricType::Connections => array_reduce($metrics, fn ($carry, $item) => array_merge($carry, $item), []),
            MetricType::Channels => $this->mergeChannels($metrics),
            MetricType::Channel => $this->mergeChannel($metrics),
            MetricType::ChannelUsers => collect($metrics)->flatten(1)->unique()->all(),
        };
    }

    /**
     * Merge multiple channel instances into a single set.
     */
    protected function mergeChannel(array $metrics): array
    {
        return collect($metrics)
            ->reduce(function ($carry, $item) {
                collect($item)->each(fn ($value, $key) => $carry->put($key, match ($key) {
                    'occupied' => $carry->get($key, false) || $value,
                    'user_count' => $carry->get($key, 0) + $value,
                    'subscription_count' => $carry->get($key, 0) + $value,
                    default => $value,
                }));

                return $carry;
            }, collect())
            ->all();
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
     * Stop listening for the given metric.
     */
    protected function stopListening(PendingMetric $metric): void
    {
        unset($this->metrics[$metric->key()]);
        $this->pubSubProvider->stopListening($metric->key());
    }
}
