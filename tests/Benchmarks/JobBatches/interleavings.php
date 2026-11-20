<?php

declare(strict_types=1);

require __DIR__ . '/benchmark.php';

// Stage both delivery orders on independent connections, holding the first transition
// uncommitted until the competing worker has entered its accounting call.
foreach (['success-first', 'failure-first', 'repeat-first'] as $order) {
    $pdo = openPdo();
    setup($pdo, $engine, $variant, 'check', 2, 1);
    $pdo = null;
    if ($order === 'repeat-first') {
        $seed = repository($engine, $variant);
        $seed->incrementFailedJobs(batchId(0), 'job', '0');
        unset($seed);
    }

    [$parent, $child] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $pid = pcntl_fork();
    check($pid >= 0, 'fork failed');
    if ($pid === 0) {
        fclose($parent);
        $second = repository($engine, $variant);
        check(fgets($child) === "start\n", 'first transition');
        fwrite($child, "ready\n");
        $result = $order === 'success-first'
            ? $second->incrementFailedJobs(batchId(0), 'job', 'second-lookup')
            : $second->decrementPendingJobs(batchId(0), 'job');
        check(($result === null) === ($order === 'success-first'), 'second transition');
        exit(0);
    }

    fclose($child);
    $first = repository($engine, $variant);
    $first->getConnection()->transaction(function () use ($first, $parent, $order) {
        $result = $order === 'success-first'
            ? $first->decrementPendingJobs(batchId(0), 'job')
            : $first->incrementFailedJobs(batchId(0), 'job', '0');
        check($result !== null, 'first transition');
        fwrite($parent, "start\n");
        check(fgets($parent) === "ready\n", 'second worker ready');
        usleep(50000);
    });
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'second worker failed');
    $fresh = $first->find(batchId(0));
    $expectedFailures = ['success-first' => 0, 'failure-first' => 1, 'repeat-first' => 2][$order];
    check($fresh->pendingJobs === 1 && $fresh->failedJobs === $expectedFailures && $fresh->failedJobIds === [], 'concurrent counters');
    unset($first);
    fclose($parent);
}
