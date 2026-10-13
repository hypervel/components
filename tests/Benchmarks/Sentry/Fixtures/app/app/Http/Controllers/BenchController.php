<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Hypervel\Contracts\Cache\Repository;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\View\Factory;
use Hypervel\Contracts\View\View;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Http\Request;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class BenchController extends Controller
{
    /**
     * Return a fixed string.
     */
    public function plain(): string
    {
        return 'plain-ok';
    }

    /**
     * Run a representative mix of queries, cache operations, logging, child coroutines and a view.
     */
    public function work(
        Request $request,
        ConnectionResolverInterface $database,
        Repository $cache,
        LoggerInterface $logger,
        Factory $views,
        ExceptionHandler $exceptions,
    ): View {
        $items = $database->connection()->table('bench_items')->where('id', '<=', 10)->get();
        $count = $database->connection()->table('bench_items')->count();
        $item = $database->connection()->table('bench_items')->where('id', 5)->first();

        $cache->put('bench.key', $item->name, 60);
        $hit = $cache->get('bench.key');
        $miss = $cache->get('bench.missing');

        [$first, $second] = parallel([
            static fn (): mixed => $cache->get('bench.key'),
            static fn (): mixed => $cache->get('bench.key'),
        ]);

        $cache->forget('bench.key');

        $logger->info('bench work', ['count' => $count]);

        if ($request->query('report') !== null) {
            $exceptions->report(new RuntimeException('bench report'));
        }

        return $views->make('bench', [
            'items' => $items,
            'count' => $count,
            'values' => [$hit, $miss, $first, $second],
        ]);
    }
}
