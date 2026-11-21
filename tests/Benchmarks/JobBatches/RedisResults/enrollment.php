<?php

declare(strict_types=1);

require __DIR__ . '/benchmark.php';

$pdo = openPdo();
setup($pdo, 'pgsql', 'always', 'clean', 2, 1);
$redis = benchRedis();
$id = batchId(0);
$state = "batch:{{$id}}:state";
$weight = 20;
$update = $pdo->prepare('update job_batches set total_jobs=total_jobs+?, pending_jobs=pending_jobs+?, finished_at=null where id=?');
$enrollments = 100;
for ($repeat = 1; $repeat <= 5; ++$repeat) {
    foreach ($repeat % 2 ? ['sql', 'invalidate'] : ['invalidate', 'sql'] as $variant) {
        $elapsed = 0;
        for ($i = 0; $i < $enrollments; ++$i) {
            // Precondition for a cache invalidation that actually removes a value.
            $redis->hSet($state, 'known_total', (string) $i);
            $start = hrtime(true);
            $pdo->beginTransaction();
            $update->execute([$weight, $variant === 'sql' ? $weight : 0, $id]);
            if ($variant === 'invalidate') {
                check($redis->hDel($state, 'known_total') === 1, 'Invalidation missed');
            }
            $pdo->commit();
            $elapsed += hrtime(true) - $start;
        }
        echo json_encode(['variant' => $variant, 'repeat' => $repeat, 'weight' => $weight,
            'enrollments' => $enrollments, 'mean_ms' => $elapsed / $enrollments / 1e6], JSON_THROW_ON_ERROR), PHP_EOL;
    }
}
