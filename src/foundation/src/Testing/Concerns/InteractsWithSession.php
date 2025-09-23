<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Session\Contracts\Session;

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
            $this->app->get(Session::class)->put($key, $value);
        }

        return $this;
    }

    /**
     * Start the session for the application.
     */
    protected function startSession(): static
    {
        if (! $this->app->get(Session::class)->isStarted()) {
            $this->app->get(Session::class)->start();
        }

        return $this;
    }

    /**
     * Flush all of the current session data.
     */
    public function flushSession(): static
    {
        $this->startSession();

        $this->app->get(Session::class)->flush();

        return $this;
    }
}
