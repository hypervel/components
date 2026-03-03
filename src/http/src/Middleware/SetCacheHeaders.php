<?php

declare(strict_types=1);

namespace Hypervel\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SetCacheHeaders
{
    /**
     * Specify the options for the middleware.
     */
    public static function using(array|string $options): string
    {
        if (is_string($options)) {
            return static::class . ':' . $options;
        }

        return (new Collection($options))
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return $value ? $key : null;
                }

                return is_int($key) ? $value : "{$key}={$value}";
            })
            ->filter()
            ->map(fn ($value) => Str::finish($value, ';'))
            ->pipe(fn ($options) => rtrim(static::class . ':' . $options->implode(''), ';'));
    }

    /**
     * Add cache related HTTP headers.
     *
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, Closure $next, array|string $options = []): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || (! $response->getContent() && ! $response instanceof BinaryFileResponse && ! $response instanceof StreamedResponse)) {
            return $response;
        }

        if (is_string($options)) {
            $options = $this->parseOptions($options);
        }

        if (! $response->isSuccessful()) {
            return $response;
        }

        if (isset($options['etag']) && $options['etag'] === true) {
            $options['etag'] = $response->getEtag() ?? ($response->getContent() ? hash('xxh128', $response->getContent()) : null);
        }

        if (isset($options['last_modified'])) {
            if (is_numeric($options['last_modified'])) {
                $options['last_modified'] = Carbon::createFromTimestamp($options['last_modified'], date_default_timezone_get());
            } else {
                $options['last_modified'] = Carbon::parse($options['last_modified']);
            }
        }

        $response->setCache($options);
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Parse the given header options.
     */
    protected function parseOptions(string $options): array
    {
        return (new Collection(explode(';', rtrim($options, ';'))))->mapWithKeys(function ($option) {
            $data = explode('=', $option, 2);

            return [$data[0] => $data[1] ?? true];
        })->all();
    }
}
