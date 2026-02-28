<?php

declare(strict_types=1);

namespace Hypervel\Coroutine\Channel;

use Hypervel\Engine\Channel;

class Manager
{
    /**
     * @var array<int, Channel>
     */
    protected array $channels = [];

    public function __construct(
        protected int $size = 1,
    ) {
    }

    /**
     * Get a channel by ID, optionally initializing it if it doesn't exist.
     */
    public function get(int $id, bool $initialize = false): ?Channel
    {
        if (isset($this->channels[$id])) {
            return $this->channels[$id];
        }

        if ($initialize) {
            return $this->channels[$id] = $this->make($this->size);
        }

        return null;
    }

    /**
     * Create a new channel with the given capacity.
     */
    public function make(int $limit): Channel
    {
        return new Channel($limit);
    }

    /**
     * Close and remove a channel by ID.
     */
    public function close(int $id): void
    {
        if ($channel = $this->channels[$id] ?? null) {
            $channel->close();
        }

        unset($this->channels[$id]);
    }

    /**
     * Get all managed channels.
     *
     * @return array<int, Channel>
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * Close and remove all managed channels.
     */
    public function flush(): void
    {
        $channels = $this->getChannels();
        foreach ($channels as $id => $channel) {
            $this->close($id);
        }
    }
}
