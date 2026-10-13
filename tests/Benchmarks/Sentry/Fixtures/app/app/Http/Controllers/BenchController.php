<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Hypervel\Contracts\View\View;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Log;
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
    public function work(Request $request): View
    {
        $items = DB::table('bench_items')->where('id', '<=', 10)->get();
        $count = DB::table('bench_items')->count();
        $item = DB::table('bench_items')->where('id', 5)->first();

        Cache::put('bench.key', $item->name, 60);
        $hit = Cache::get('bench.key');
        $miss = Cache::get('bench.missing');

        [$first, $second] = parallel([
            static fn (): mixed => Cache::get('bench.key'),
            static fn (): mixed => Cache::get('bench.key'),
        ]);

        Cache::forget('bench.key');

        Log::info('bench work', ['count' => $count]);

        if ($request->query('report') !== null) {
            report(new RuntimeException('bench report'));
        }

        return view('bench', [
            'items' => $items,
            'count' => $count,
            'values' => [$hit, $miss, $first, $second],
        ]);
    }
}
