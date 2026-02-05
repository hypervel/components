<?php

declare(strict_types=1);

namespace Hypervel\Guzzle;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use Hypervel\Contracts\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Swoole\Runtime;

class ClientFactory
{
    /**
     * Whether Swoole extension is loaded.
     */
    protected bool $runInSwoole = false;

    /**
     * The native curl hook flag value.
     */
    protected int $nativeCurlHook = 0;

    /**
     * Create a new client factory instance.
     */
    public function __construct(private Container $container)
    {
        $this->runInSwoole = extension_loaded('swoole');
        if (defined('SWOOLE_HOOK_NATIVE_CURL')) {
            $this->nativeCurlHook = SWOOLE_HOOK_NATIVE_CURL;
        }
    }

    /**
     * Create a new Guzzle client instance.
     */
    public function create(array $options = []): Client
    {
        $stack = null;

        if (
            $this->runInSwoole
            && Coroutine::inCoroutine()
            && (Runtime::getHookFlags() & $this->nativeCurlHook) == 0
        ) {
            $stack = HandlerStack::create(new CoroutineHandler());
        }

        $config = array_replace(['handler' => $stack], $options);

        return $this->container->make(Client::class, ['config' => $config]);
    }
}
