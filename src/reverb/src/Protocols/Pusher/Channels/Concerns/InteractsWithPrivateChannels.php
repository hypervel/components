<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Protocols\Pusher\Channels\Concerns;

use Hypervel\Reverb\Contracts\Connection;
use Hypervel\Reverb\Protocols\Pusher\Exceptions\ConnectionUnauthorized;
use Hypervel\Support\Str;

trait InteractsWithPrivateChannels
{
    /**
     * Subscribe to the given channel.
     */
    public function subscribe(Connection $connection, ?string $auth = null, ?string $data = null, ?string $userId = null): void
    {
        $this->verify($connection, $auth, $data);

        parent::subscribe($connection, $auth, $data, $userId);
    }

    /**
     * Determine whether the given authentication token is valid.
     */
    protected function verify(Connection $connection, ?string $auth = null, ?string $data = null): bool
    {
        if ($auth === null) {
            throw new ConnectionUnauthorized();
        }

        $signature = "{$connection->id()}:{$this->name()}";

        if ($data) {
            $signature .= ":{$data}";
        }

        if (! hash_equals(
            hash_hmac(
                'sha256',
                $signature,
                $connection->app()->secret(),
            ),
            Str::after($auth, ':')
        )) {
            throw new ConnectionUnauthorized();
        }

        return true;
    }
}
