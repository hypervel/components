<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Hyperf\Collection\Collection;
use Hyperf\Database\Model\Model;
use Hypervel\Broadcasting\BroadcastEvent;
use Hypervel\Event\CallQueuedListener;
use Hypervel\Mail\SendQueuedMailable;
use Hypervel\Notifications\SendQueuedNotifications;
use Illuminate\Events\CallQueuedListener as IlluminateCallQueuedListener;
use ReflectionClass;
use ReflectionException;
use stdClass;

class ExtractTags
{
    /**
     * Get the tags for the given object.
     */
    public static function from(mixed $target): array
    {
        if ($tags = static::explicitTags([$target])) {
            return $tags;
        }

        return static::modelsFor([$target])->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Determine the tags for the given job.
     */
    public static function fromJob(mixed $job): array
    {
        if ($tags = static::extractExplicitTags($job)) {
            return $tags;
        }

        return static::modelsFor(static::targetsFor($job))->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Determine the tags for the given array.
     */
    public static function fromArray(array $data): array
    {
        return Collection::make($data)->map(function ($value) {
            return static::resolveValue($value);
        })->collapse()->filter()->map(function ($model) {
            return FormatModel::given($model);
        })->all();
    }

    /**
     * Extract tags from job object.
     */
    protected static function extractExplicitTags(mixed $job): array
    {
        return ($job instanceof CallQueuedListener || $job instanceof IlluminateCallQueuedListener)
            ? static::tagsForListener($job)
            : static::explicitTags(static::targetsFor($job));
    }

    /**
     * Determine tags for the given queued listener.
     */
    protected static function tagsForListener(mixed $job): array
    {
        return Collection::make(
            [static::extractListener($job), static::extractEvent($job)]
        )->map(function ($job) {
            return static::from($job);
        })->collapse()->unique()->toArray();
    }

    /**
     * Determine tags for the given job.
     */
    protected static function explicitTags(array $targets): array
    {
        return Collection::make($targets)->map(function ($target) {
            return method_exists($target, 'tags') ? $target->tags() : [];
        })->collapse()->unique()->all();
    }

    /**
     * Get the actual target for the given job.
     */
    protected static function targetsFor(mixed $job): array
    {
        switch (true) {
            case $job instanceof BroadcastEvent: // @phpstan-ignore-line
                return [$job->event]; // @phpstan-ignore-line
            case $job instanceof CallQueuedListener || $job instanceof IlluminateCallQueuedListener:
                return [static::extractEvent($job)];
            case $job instanceof SendQueuedMailable:
                return [$job->mailable];
            case $job instanceof SendQueuedNotifications:
                return [$job->notification];
            default:
                return [$job];
        }
    }

    /**
     * Get the models from the given object.
     */
    protected static function modelsFor(array $targets): Collection
    {
        return Collection::make($targets)->map(function ($target) {
            return Collection::make((new ReflectionClass($target))->getProperties())->map(function ($property) use ($target) {
                $property->setAccessible(true);

                if (! is_object($target) || $property->isInitialized($target)) {
                    return static::resolveValue($property->getValue($target));
                }
            })->collapse()->filter();
        })->collapse()->unique();
    }

    /**
     * Extract the listener from a queued job.
     *
     * @throws ReflectionException
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
     * Resolve the given value.
     */
    protected static function resolveValue(mixed $value): ?Collection
    {
        switch (true) {
            case $value instanceof Model:
                return Collection::make([$value]);
            case $value instanceof Collection:
                return $value->flatten();
        }

        return null;
    }
}
