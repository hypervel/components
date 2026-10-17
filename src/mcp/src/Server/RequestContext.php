<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server;

use Generator;
use Hypervel\Context\CoroutineContext;
use Hypervel\Mcp\Request;
use Traversable;

/**
 * Keep an MCP invocation's request available through eager and lazy handlers.
 *
 * @internal
 */
class RequestContext
{
    public const string LIBRARY_SCRIPTS_CONTEXT_KEY = '__mcp.library_scripts';

    /**
     * Run an operation with its request and preserve lazy response ownership.
     */
    public static function run(Request $request, callable $callback): mixed
    {
        $previous = self::install($request, null);

        try {
            $response = $callback();
            $scripts = CoroutineContext::get(self::LIBRARY_SCRIPTS_CONTEXT_KEY);
        } finally {
            self::restore($previous);
        }

        return $response instanceof Traversable
            ? self::stream($request, $scripts, $response)
            : $response;
    }

    /**
     * Consume a lazy response under the request that created it.
     *
     * @param Traversable<mixed, mixed> $response
     * @return Generator<mixed, mixed>
     */
    private static function stream(Request $request, ?string $scripts, Traversable $response): Generator
    {
        $previous = self::install($request, $scripts);

        try {
            yield from $response;
        } finally {
            // A suspended handler's finally block must still see its own request.
            try {
                unset($response);
            } finally {
                self::restore($previous);
            }
        }
    }

    /**
     * Install an invocation and return the previous context.
     *
     * @return array{?Request, ?string}
     */
    private static function install(Request $request, ?string $scripts): array
    {
        $previous = [
            CoroutineContext::get(Request::class),
            CoroutineContext::get(self::LIBRARY_SCRIPTS_CONTEXT_KEY),
        ];

        CoroutineContext::set(Request::class, $request);

        $scripts === null
            ? CoroutineContext::forget(self::LIBRARY_SCRIPTS_CONTEXT_KEY)
            : CoroutineContext::set(self::LIBRARY_SCRIPTS_CONTEXT_KEY, $scripts);

        return $previous;
    }

    /**
     * Restore the context displaced by an invocation.
     *
     * @param array{?Request, ?string} $previous
     */
    private static function restore(array $previous): void
    {
        [$request, $scripts] = $previous;

        $request === null
            ? CoroutineContext::forget(Request::class)
            : CoroutineContext::set(Request::class, $request);

        $scripts === null
            ? CoroutineContext::forget(self::LIBRARY_SCRIPTS_CONTEXT_KEY)
            : CoroutineContext::set(self::LIBRARY_SCRIPTS_CONTEXT_KEY, $scripts);
    }
}
