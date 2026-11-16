<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;

use function Hypervel\Coroutine\parallel;

class ListenerContextIsolationTest extends TestCase
{
    use VerifiesDoubles;

    public function testQueriesAreIsolatedBetweenCoroutines(): void
    {
        $results = parallel([
            'a' => fn (): array => $this->recordQueries('conn-a', ['SELECT a1', 'SELECT a2']),
            'b' => fn (): array => $this->recordQueries('conn-b', ['SELECT b1']),
        ]);

        $this->assertSame(2, $results['a']['count']);
        $this->assertSame(['SELECT a1', 'SELECT a2'], $results['a']['sqls']);
        $this->assertSame(1, $results['b']['count']);
        $this->assertSame(['SELECT b1'], $results['b']['sqls']);
    }

    /**
     * Record queries in the current coroutine context.
     *
     * @param string[] $queries
     * @return array{count: int, sqls: string[]}
     */
    private function recordQueries(string $connectionName, array $queries): array
    {
        $listener = new Listener;
        $connection = Double::for(Connection::class);
        $connection->allows('getName')->returns($connectionName);
        $connection->allows('prepareBindings')->returns([]);

        foreach ($queries as $index => $query) {
            $listener->onQueryExecuted(
                new QueryExecuted($query, [], (float) ($index + 1), $connection),
            );
        }

        // Interleave the stored queries before reading them back.
        usleep(5000);

        return [
            'count' => count($listener->queries()),
            'sqls' => array_column($listener->queries(), 'sql'),
        ];
    }
}
