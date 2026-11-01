<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http\Middleware;

use Closure;
use Hypervel\Database\Connection;
use Hypervel\Database\DatabaseManager;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Date;

class PreventStaleDatabaseReads
{
    /**
     * Create a new middleware instance.
     */
    public function __construct(protected DatabaseManager $manager)
    {
    }

    /**
     * Specify the delay for the middleware.
     */
    public static function for(int $seconds): string
    {
        return static::class . ':' . $seconds;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, int|string $seconds = 2): mixed
    {
        $name = $this->manager->getDefaultConnection();
        $recentlyModified = $request->session()->get('_database_modified_at', 0) >= Date::now()->subSeconds((int) $seconds)->getTimestamp();

        // Resolving a pooled connection takes a slot even before its first query.
        // Without a recent write, inspect existing state so query-free routes do not exhaust the pool.
        /** @var null|Connection $connection */
        $connection = $recentlyModified
            ? $this->manager->connection($name)
            : $this->manager->getHeldConnection($name);
        $connection?->useWriteConnectionWhenReading($recentlyModified);

        $response = $next($request);

        // The request may have resolved its first connection, or changed the default name.
        $connection ??= $this->manager->getHeldConnection($name);

        /** @var null|Connection $connection */
        if ($connection?->hasModifiedRecords()) {
            $request->session()->put('_database_modified_at', Date::now()->getTimestamp());
        }

        return $response;
    }
}
