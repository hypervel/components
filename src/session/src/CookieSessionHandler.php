<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Cookie\QueueingFactory as CookieJar;
use Hypervel\Http\Request;
use Hypervel\Support\InteractsWithTime;
use SessionHandlerInterface;

class CookieSessionHandler implements SessionHandlerInterface
{
    use InteractsWithTime;

    /**
     * Context key prefix for the current request.
     */
    protected const REQUEST_CONTEXT_KEY_PREFIX = '__session.cookie.request.';

    /**
     * Create a new cookie driven handler instance.
     *
     * @param CookieJar $cookie the cookie jar instance
     * @param int $minutes the number of minutes the session should be valid
     * @param bool $expireOnClose indicates whether the session should be expired when the browser closes
     */
    public function __construct(
        protected CookieJar $cookie,
        protected int $minutes,
        protected bool $expireOnClose = false
    ) {
    }

    public function open(string $savePath, string $sessionName): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $sessionId): false|string
    {
        $value = $this->getRequest()->cookies->get($sessionId) ?: '';

        if (! is_null($decoded = json_decode($value, true))
            && is_array($decoded)
            && isset($decoded['expires'])
            && $this->currentTime() <= $decoded['expires']
        ) {
            return $decoded['data'];
        }

        return '';
    }

    public function write(string $sessionId, string $data): bool
    {
        $this->cookie->queue($sessionId, json_encode([
            'data' => $data,
            'expires' => $this->availableAt($this->minutes * 60),
        ]), $this->expireOnClose ? 0 : $this->minutes);

        return true;
    }

    public function destroy(string $sessionId): bool
    {
        $this->cookie->queue($this->cookie->forget($sessionId));

        return true;
    }

    public function gc(int $lifetime): int
    {
        return 0;
    }

    /**
     * Set the request instance.
     */
    public function setRequest(Request $request): void
    {
        CoroutineContext::set(self::REQUEST_CONTEXT_KEY_PREFIX . spl_object_id($this), $request);
    }

    /**
     * Get the request instance for the current coroutine.
     */
    protected function getRequest(): Request
    {
        return CoroutineContext::get(self::REQUEST_CONTEXT_KEY_PREFIX . spl_object_id($this));
    }
}
