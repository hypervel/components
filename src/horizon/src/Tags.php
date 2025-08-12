<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Context\Context;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Event\CallQueuedListener;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Support\Collection;
use ReflectionClass;
use ReflectionProperty;
use stdClass;

class Tags
{
    protected const CONTEXT_KEY = 'horizon.tags';

    /**
     * Determine the tags for the given job.
     */
    public static function for(mixed $job): array
    {
        if ($tags = static::extractExplicitTags($job)) {
            return $tags;
        }

        return static::modelsFor(static::targetsFor($job))->map(function ($model) {
            return get_class($model) . ':' . $model->getKey();
        })->all();
    }

    /**
     * Extract tags from job object.
     */
    public static function extractExplicitTags(mixed $job): array
    {
        return $job instanceof CallQueuedListener
                    ? static::tagsForListener($job)
                    : static::explicitTags(static::targetsFor($job));
    }

    /**
     * Determine tags for the given queued listener.
     */
    protected static function tagsForListener(CallQueuedListener $job): array
    {
        $event = static::extractEvent($job);

        static::setEvent($event);

        return collect(
            [static::extractListener($job), $event]
        )->map(function ($job) {
            return static::for($job);
        })->collapse()->unique()->tap(function () {
            static::flushEventState();
        })->toArray();
    }

    /**
     * Determine tags for the given job.
     */
    protected static function explicitTags(array $jobs): array
    {
        return collect($jobs)->map(function ($job) {
            return method_exists($job, 'tags') ? $job->tags(static::getEvent()) : [];
        })->collapse()->unique()->all();
    }

    /**
     * Get the actual target for the given job.
     */
    public static function targetsFor(mixed $job): array
    {
        return match (true) {
            $job instanceof BroadcastEvent => [$job->event],
            $job instanceof CallQueuedListener => [static::extractEvent($job)],
            $job instanceof SendQueuedMailable => [$job->mailable],
            $job instanceof SendQueuedNotifications => [$job->notification],
            default => [$job],
        };
    }

    /**
     * Get the models from the given object.
     */
    public static function modelsFor(array $targets): Collection
    {
        $models = [];

        foreach ($targets as $target) {
            $models[] = collect(
                (new ReflectionClass($target))->getProperties()
            )->map(function ($property) use ($target) {
                $property->setAccessible(true);

                $value = static::getValue($property, $target);

                if ($value instanceof Model) {
                    return [$value];
                }
                if ($value instanceof EloquentCollection) {
                    return $value->all();
                }
            })->collapse()->filter()->all();
        }

        return collect($models)->collapse()->unique();
    }

    /**
     * Get the value of the given ReflectionProperty.
     */
    protected static function getValue(ReflectionProperty $property, mixed $target): mixed
    {
        if (method_exists($property, 'isInitialized')
            && ! $property->isInitialized($target)
        ) {
            return null;
        }

        return $property->getValue($target);
    }

    /**
     * Extract the listener from a queued job.
     */
    protected static function extractListener(mixed $job): mixed
    {
        return (new ReflectionClass($job->class))->newInstanceWithoutConstructor();
    }

    /**
     * Extract the event from a queued job.
     */
    protected static function extractEvent(mixed $job): mixed
    {
        return isset($job->data[0]) && is_object($job->data[0])
                        ? $job->data[0]
                        : new stdClass();
    }

    /**
     * Set the event currently being handled.
     */
    protected static function setEvent(object $event): void
    {
        Context::set(static::CONTEXT_KEY, $event);
    }

    protected static function getEvent(): ?object
    {
        return Context::get(static::CONTEXT_KEY);
    }

    /**
     * Flush the event currently being handled.
     */
    protected static function flushEventState(): void
    {
        Context::set(static::CONTEXT_KEY, null);
    }
}
