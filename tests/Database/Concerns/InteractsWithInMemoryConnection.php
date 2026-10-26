<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Concerns;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\SQLiteConnection;

/**
 * Hypervel's counterpart to Laravel's RestoresConnectionResolver test trait.
 *
 * AfterEachTestSubscriber resets the model connection resolver after every
 * test, so this trait only provides the in-memory connection.
 */
trait InteractsWithInMemoryConnection
{
    /**
     * Resolve models through a fresh in-memory SQLite connection.
     */
    protected function useInMemoryConnection(): SQLiteConnection
    {
        $db = new DB;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $db->bootEloquent();

        /** @var SQLiteConnection */
        return $db->getConnection();
    }
}
