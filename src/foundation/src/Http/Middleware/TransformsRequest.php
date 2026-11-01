<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Http\Exceptions\PostTooLargeException;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

class TransformsRequest
{
    protected const string KEY_BYTES_CONTEXT_KEY = '__foundation.transforms_request.key_bytes';

    /**
     * The maximum cumulative size of the keys processed per request, in bytes.
     */
    protected int $maxKeyBytes = 20 * 1024 * 1024;

    /**
     * The cumulative size of the keys processed for the current request.
     */
    protected int $keyBytes {
        // Middleware instances are shared; custom transforms can yield during request processing.
        get => CoroutineContext::get(self::KEY_BYTES_CONTEXT_KEY, 0);
        set {
            CoroutineContext::set(self::KEY_BYTES_CONTEXT_KEY, $value);
        }
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $this->keyBytes = 0;

        $this->clean($request);

        return $next($request);
    }

    /**
     * Clean the request's data.
     */
    protected function clean(Request $request): void
    {
        $this->cleanParameterBag($request->query);

        if ($request->isJson()) {
            $this->cleanParameterBag($request->json());
        } elseif ($request->request !== $request->query) {
            $this->cleanParameterBag($request->request);
        }
    }

    /**
     * Clean the data in the parameter bag.
     */
    protected function cleanParameterBag(ParameterBag $bag): void
    {
        if ($bag->count() === 0) {
            return;
        }

        $bag->replace($this->cleanArray($bag->all()));
    }

    /**
     * Clean the data in the given array.
     */
    protected function cleanArray(array $data, string $keyPrefix = ''): array
    {
        $prefixLength = strlen($keyPrefix);
        $keyBytes = $this->keyBytes;

        // Charge the array before transforming values, keeping context lookups out of the per-key loop.
        foreach ($data as $key => $value) {
            $keyBytes += $prefixLength + strlen((string) $key);

            if ($keyBytes > $this->maxKeyBytes) {
                $this->keyBytes = $keyBytes;

                throw new PostTooLargeException('The cumulative size of the input keys is too large.');
            }
        }

        $this->keyBytes = $keyBytes;

        foreach ($data as $key => $value) {
            $cleaned = $this->cleanValue($keyPrefix . $key, $value);

            // Avoid copying arrays when none of their values have changed...
            if ($cleaned !== $value) {
                $data[$key] = $cleaned;
            }
        }

        return $data;
    }

    /**
     * Clean the given value.
     */
    protected function cleanValue(string $key, mixed $value): mixed
    {
        if (is_array($value)) {
            return $this->cleanArray($value, $key . '.');
        }

        return $this->transform($key, $value);
    }

    /**
     * Transform the given value.
     */
    protected function transform(string $key, mixed $value): mixed
    {
        return $value;
    }
}
