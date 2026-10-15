<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Handler\CurlFactory;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\CurlShareHandleState;
use GuzzleHttp\Handler\CurlVersion;
use GuzzleHttp\Handler\Proxy;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\TransportSharing;
use GuzzleHttp\Utils;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;

class HandlerFactory
{
    /**
     * The idle cURL handles retained for reuse by default.
     */
    public const int DEFAULT_MAX_IDLE_HANDLES = 256;

    /**
     * Create a transport handler with bounded synchronous connection reuse.
     *
     * Guzzle's synchronous cURL handler keeps three idle easy handles, and
     * each keeps its own keep-alive connections, so a connection used by
     * concurrent coroutines would reconnect for most requests. Guzzle still
     * selects and validates the handler; synchronous requests it would send
     * through cURL use a handler that keeps "max_idle_handles" instead.
     */
    public static function create(array $options = []): callable
    {
        $maxIdleHandles = $options['max_idle_handles'] ?? self::DEFAULT_MAX_IDLE_HANDLES;
        unset($options['max_idle_handles']);

        $upstream = Utils::chooseHandler($options);
        $transportSharing = $options['transport_sharing'] ?? null;
        // Create the retaining handler lazily; async and streamed requests never use it.
        $retaining = null;
        $handler = static function (RequestInterface $request, array $options) use (&$retaining, $upstream, $transportSharing, $maxIdleHandles): PromiseInterface {
            $retaining ??= static::createIdleHandleRetainingHandler($transportSharing, $maxIdleHandles) ?? $upstream;

            return $retaining($request, $options);
        };

        // Guzzle 7 alone sends TLS 1.2 requests that its cURL cannot honor to its stream handler.
        // @phpstan-ignore function.impossibleType (the method exists in Guzzle 7, not in the installed Guzzle 8)
        if (method_exists(Proxy::class, 'wrapTlsFallback')) {
            $handler = Proxy::wrapTlsFallback($handler, $upstream);
        }

        return Proxy::wrapStreaming(Proxy::wrapSync($upstream, $handler), $upstream);
    }

    /**
     * Create a synchronous cURL handler that keeps the given number of idle handles, or null when Guzzle would not send through cURL.
     *
     * Guzzle offers no handler option for the idle handle limit, so this
     * builds the handler its selection would, through its internal share
     * state and version checks.
     */
    protected static function createIdleHandleRetainingHandler(mixed $transportSharing, int $maxIdleHandles): ?CurlHandler
    {
        if (! function_exists('curl_exec') || ! defined('CURLOPT_CUSTOMREQUEST') || ! CurlVersion::supportsCurlHandler()) {
            return null;
        }

        try {
            $shareState = CurlShareHandleState::fromOption($transportSharing);
        } catch (InvalidArgumentException) {
            // Guzzle validated the same sharing when it selected its handler, which stays correct on its own.
            return null;
        }

        return new CurlHandler([
            'handle_factory' => new CurlFactory($maxIdleHandles, $shareState->mode ?? TransportSharing::NONE, $shareState),
        ]);
    }
}
