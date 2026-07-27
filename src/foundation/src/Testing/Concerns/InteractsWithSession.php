<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

trait InteractsWithSession
{
    /**
     * Set the session to the given array.
     */
    public function withSession(array $data): static
    {
        $this->session($data);

        return $this;
    }

    /**
     * Set the session to the given array.
     */
    public function session(array $data): static
    {
        $this->startSession();

        foreach ($data as $key => $value) {
            $this->app['session']->put($key, $value);
        }

        return $this;
    }

    /**
     * Start the session for the application.
     */
    protected function startSession(): static
    {
        if (! $this->app['session']->isStarted()) {
            $this->app['session']->start();
        }

        return $this;
    }

    /**
     * Flush all of the current session data.
     */
    public function flushSession(): static
    {
        $this->startSession();

        $this->app['session']->flush();

        return $this;
    }
}
