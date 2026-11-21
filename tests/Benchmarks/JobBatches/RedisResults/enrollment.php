<?php

declare(strict_types=1);

require __DIR__ . '/benchmark.php';

$pdo = openPdo();
setup($pdo, 'pgsql', 'always', 'clean', 2, 1);
$redis = benchRedis();
$id = batchId(0);
$state = "batches:{{$id}}:state";
$weight = 20;
$repositories = ['sql' => repository('pgsql', 'sql'), 'redis' => repository('pgsql', 'always')];
$enrollments = 100;
for ($repeat = 1; $repeat <= 5; ++$repeat) {
    foreach ($repeat % 2 ? ['sql', 'redis'] : ['redis', 'sql'] as $variant) {
        $elapsed = 0;
        for ($i = 0; $i < $enrollments; ++$i) {
            // Precondition for a cache invalidation that actually removes a value.
            $redis->hSet($state, 'known_total', (string) $i);
            $start = hrtime(true);
            $repository = $repositories[$variant];
            $repository->transaction(static fn () => $repository->incrementTotalJobs($id, $weight));
            $elapsed += hrtime(true) - $start;
        }
        echo json_encode(['variant' => $variant, 'repeat' => $repeat, 'weight' => $weight,
            'enrollments' => $enrollments, 'mean_ms' => $elapsed / $enrollments / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    }
}
