<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Events;

use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Reflector;
use Hypervel\Support\Str;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

class DiscoverEvents
{
    /**
     * The callback to be used to guess class names.
     *
     * @var null|(callable(SplFileInfo, string): class-string)
     */
    public static $guessClassNamesUsingCallback;

    /**
     * Get all of the events and listeners by searching the given listener directory.
     *
     * @return array<class-string, array<int, string>>
     */
    public static function within(array|string $listenerPath, string $basePath): array
    {
        if (Arr::wrap($listenerPath) === []) {
            return [];
        }

        $listeners = new Collection(static::getListenerEvents(
            Finder::create()->files()->in($listenerPath),
            $basePath
        ));

        $discoveredEvents = [];

        foreach ($listeners as $listener => $events) {
            foreach ($events as $event) {
                if (! isset($discoveredEvents[$event])) {
                    $discoveredEvents[$event] = [];
                }

                $discoveredEvents[$event][] = $listener;
            }
        }

        return $discoveredEvents;
    }

    /**
     * Get all of the listeners and their corresponding events.
     *
     * @param iterable<string, SplFileInfo> $listeners
     * @return array<string, array<int, class-string>>
     */
    protected static function getListenerEvents(iterable $listeners, string $basePath): array
    {
        $listenerEvents = [];

        foreach ($listeners as $listener) {
            try {
                $listener = new ReflectionClass(
                    static::classFromFile($listener, $basePath)
                );
            } catch (ReflectionException) {
                continue;
            }

            if (! $listener->isInstantiable()) {
                continue;
            }

            foreach ($listener->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ((! Str::is('handle*', $method->name) && ! Str::is('__invoke', $method->name))
                    || ! isset($method->getParameters()[0])) {
                    continue;
                }

                $listenerEvents[$listener->name . '@' . $method->name]
                    = Reflector::getParameterClassNames($method->getParameters()[0]);
            }
        }

        return array_filter($listenerEvents);
    }

    /**
     * Extract the class name from the given file path.
     *
     * @return class-string
     */
    protected static function classFromFile(SplFileInfo $file, string $basePath): string
    {
        if (static::$guessClassNamesUsingCallback) {
            return call_user_func(static::$guessClassNamesUsingCallback, $file, $basePath);
        }

        $class = trim(Str::replaceFirst($basePath, '', $file->getRealPath()), DIRECTORY_SEPARATOR);

        return ucfirst(Str::camel(str_replace(
            [DIRECTORY_SEPARATOR, ucfirst(basename(app()->path())) . '\\'],
            ['\\', app()->getNamespace()],
            ucfirst(Str::replaceLast('.php', '', $class))
        )));
    }

    /**
     * Specify a callback to be used to guess class names.
     *
     * @param callable(SplFileInfo, string): class-string $callback
     */
    public static function guessClassNamesUsing(callable $callback): void
    {
        static::$guessClassNamesUsingCallback = $callback;
    }

    /**
     * Flush the class's static state.
     */
    public static function flushState(): void
    {
        static::$guessClassNamesUsingCallback = null;
    }
}
