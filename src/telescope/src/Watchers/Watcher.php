<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Arr;
use Hypervel\Telescope\Telescope;

abstract class Watcher
{
    /**
     * Create a new watcher instance.
     *
     * @param array $options the configured watcher options
     */
    public function __construct(
        public array $options = []
    ) {
    }

    /**
     * Register the watcher.
     */
    abstract public function register(Application $app): void;

    /**
     * Set the watcher options.
     */
    public function setOptions(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Hide the given parameters.
     */
    protected function hideParameters(array $data, array $hidden): array
    {
        foreach ($hidden as $parameter) {
            if (Arr::has($data, $parameter)) {
                Arr::set($data, $parameter, Telescope::REDACTED_VALUE);
            }
        }

        return $data;
    }

    /**
     * Hide query credentials without changing unrelated URL bytes.
     */
    protected function hideQueryParameters(string $uri): string
    {
        $queryStart = strpos($uri, '?');
        $fragmentStart = strpos($uri, '#');

        if ($queryStart === false || ($fragmentStart !== false && $fragmentStart < $queryStart)) {
            return $uri;
        }

        $queryEnd = $fragmentStart === false ? strlen($uri) : $fragmentStart;
        $parts = explode('&', substr($uri, $queryStart + 1, $queryEnd - $queryStart - 1));

        // Parsing into an array would collapse repeated keys and rewrite dotted names.
        foreach ($parts as $index => $part) {
            $name = explode('=', $part, 2)[0];
            $decodedName = urldecode($name);
            $segments = explode('[', $decodedName);
            $parameter = array_shift($segments);

            // Hidden parents cover descendants, but an appended [] has no addressable key.
            foreach ($segments as $segment) {
                if (in_array($parameter, Telescope::$hiddenRequestParameters, true)) {
                    break;
                }

                $key = strstr($segment, ']', true);

                if ($key === false || $key === '') {
                    break;
                }

                $parameter .= '.' . $key;
            }

            if (in_array($decodedName, Telescope::$hiddenRequestParameters, true)
                || in_array($parameter, Telescope::$hiddenRequestParameters, true)) {
                $parts[$index] = $name . '=' . Telescope::REDACTED_VALUE;
            }
        }

        return substr($uri, 0, $queryStart + 1) . implode('&', $parts) . substr($uri, $queryEnd);
    }
}
