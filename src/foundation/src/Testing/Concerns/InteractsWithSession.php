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
        $session = $this->app->make('session');

        foreach ($data as $key => $value) {
            $session->put($key, $value);
        }

        return $this;
    }

    /**
     * Start the session for the application.
     */
    protected function startSession(): static
    {
        $session = $this->app->make('session');

        if (! $session->isStarted()) {
            $session->start();
        }

        return $this;
    }

    /**
     * Flush all of the current session data.
     */
    public function flushSession(): static
    {
        $this->startSession();

        $this->app->make('session')->flush();

        return $this;
    }
}
