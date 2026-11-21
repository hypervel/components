<?php

declare(strict_types=1);

require __DIR__ . '/benchmark.php';

$pdo = openPdo();
setup($pdo, 'pgsql', 'always', 'clean', 2, 1);
$repo = repository('pgsql', 'always');
$redis = benchRedis();
$id = batchId(0);
$key = "batch:{{$id}}:state";
$first = jobId(1);
$second = jobId(2);
$repo->find($id)->recordSuccessfulJob($first);
check((int) $redis->hGet($key, 'known_total') === 2, 'First result certified the initial total');

foreach (['rollback', 'commit'] as $action) {
    $pdo->beginTransaction();
    $pdo->prepare('update job_batches set total_jobs = total_jobs + 1, finished_at = null where id = ?')->execute([$id]);
    $redis->hDel($key, 'known_total');
    check($repo->find($id)->totalJobs === 2, 'Uncommitted add visible to independent reader');
    $action === 'rollback' ? $pdo->rollBack() : $pdo->commit();
    if ($action === 'rollback') {
        check($repo->find($id)->pendingJobs === 1, 'Rollback retained the extra job');
    }
}
$counts = $repo->find($id)->decrementPendingJobs($second);
check($counts?->totalJobs === 3 && $counts->pendingJobs === 1 && ! $repo->find($id)->finished(), 'Late committed add lost');
check($repo->find($id)->decrementPendingJobs($second) === null, 'Duplicate result counted');
$repo->find($id)->recordSuccessfulJob(jobId(3));
$finished = $repo->find($id)->finishedAt->getTimestamp();
$pdo->prepare('update job_batches set finished_at = null where id = ?')->execute([$id]);
check($repo->find($id)->finishedAt->getTimestamp() === $finished, 'Redis marker did not survive a missing SQL projection');

$pdo->exec('create temporary table job_batch_deletions(batch_id uuid primary key)');
foreach (['rollback', 'commit'] as $action) {
    $pdo->beginTransaction();
    $pdo->prepare('delete from job_batches where id = ?')->execute([$id]);
    $pdo->prepare('insert into job_batch_deletions values (?)')->execute([$id]);
    $redis->hDel($key, 'known_total');
    $action === 'rollback' ? $pdo->rollBack() : $pdo->commit();
    if ($action === 'rollback') {
        check($repo->find($id)->finished() && $repo->successes($id) === 3, 'Rollback lost data');
        check($repo->find($id)->decrementPendingJobs($second) === null, 'Replay counted after rolled-back delete');
    }
}
check($repo->find($id) === null && $repo->success($id, jobId(4), 3, true) === null, 'Deleted batch accepted work');
check($pdo->query('select batch_id from job_batch_deletions')->fetchColumn() === $id, 'Missing cleanup record');
$redis->unlink($key, "batch:{{$id}}:successes", "batch:{{$id}}:failures");
$pdo->prepare('delete from job_batch_deletions where batch_id = ?')->execute([$id]);
check($repo->successes($id) === 0 && $repo->find($id) === null, 'Cleanup failed');
echo "PostgreSQL and Redis protocol probes passed.\n";
