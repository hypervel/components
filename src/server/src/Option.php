<?php

declare(strict_types=1);

namespace Hypervel\Server;

use Hypervel\HttpServer\PriorityMiddleware;

class Option
{
    protected int $sendChannelCapacity = 0;

    protected bool $enableRequestLifecycle = false;

    protected bool $mustSortMiddlewares = false;

    /**
     * Create an Option instance from an array or existing Option.
     */
    public static function make(array|Option $options): Option
    {
        if ($options instanceof Option) {
            return $options;
        }

        return tap(new self(), function (Option $option) use ($options) {
            $option->setSendChannelCapacity($options['send_channel_capacity'] ?? 0);
            $option->setEnableRequestLifecycle($options['enable_request_lifecycle'] ?? false);
        });
    }

    /**
     * Get the send channel capacity.
     */
    public function getSendChannelCapacity(): int
    {
        return $this->sendChannelCapacity;
    }

    /**
     * Set the send channel capacity.
     */
    public function setSendChannelCapacity(int $sendChannelCapacity): static
    {
        $this->sendChannelCapacity = $sendChannelCapacity;
        return $this;
    }

    /**
     * Determine if request lifecycle events are enabled.
     */
    public function isEnableRequestLifecycle(): bool
    {
        return $this->enableRequestLifecycle;
    }

    /**
     * Set whether request lifecycle events are enabled.
     */
    public function setEnableRequestLifecycle(bool $enableRequestLifecycle): static
    {
        $this->enableRequestLifecycle = $enableRequestLifecycle;
        return $this;
    }

    /**
     * Determine if middlewares must be sorted by priority.
     */
    public function isMustSortMiddlewares(): bool
    {
        return $this->mustSortMiddlewares;
    }

    /**
     * Set whether middlewares must be sorted by priority.
     */
    public function setMustSortMiddlewares(bool $mustSortMiddlewares): static
    {
        $this->mustSortMiddlewares = $mustSortMiddlewares;
        return $this;
    }

    /**
     * Set whether middlewares must be sorted based on the middleware list.
     */
    public function setMustSortMiddlewaresByMiddlewares(array $middlewares): static
    {
        foreach ($middlewares as $middleware) {
            if (is_int($middleware) || $middleware instanceof PriorityMiddleware) {
                return $this->setMustSortMiddlewares(true);
            }
        }
        return $this;
    }
}
