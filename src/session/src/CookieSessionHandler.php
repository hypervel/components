<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hyperf\HttpServer\Request;
use Hypervel\Cookie\Contracts\Cookie as CookieContract;
use Hypervel\Support\Traits\InteractsWithTime;
use SessionHandlerInterface;

class CookieSessionHandler implements SessionHandlerInterface
{
    use InteractsWithTime;

    /**
     * Create a new cookie driven handler instance.
     *
     * @param CookieContract $cookie the cookie jar instance
     * @param Request $request the request instance
     * @param int $minutes the number of minutes the session should be valid
     * @param bool $expireOnClose indicates whether the session should be expired when the browser closes
     */
    public function __construct(
        protected CookieContract $cookie,
        protected Request $request,
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
        $value = $this->request->cookie($sessionId);

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
        $this->request = $request;
    }
}
