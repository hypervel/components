<?php

declare(strict_types=1);

require __DIR__ . '/benchmark.php';

$pdo = openPdo();
setup($pdo, $engine, 'C', 'clean', 100001, 1);
for ($chunk = 0; $chunk < 200; ++$chunk) {
    $values = [];
    for ($i = 0; $i < 500; ++$i) {
        array_push($values, batchId(0), hash('xxh128', jobId($chunk * 500 + $i)));
    }
    $pdo->prepare('insert into job_outcomes(batch_id,job_id_hash) values ' . implode(',', array_fill(0, 500, '(?,?)')))->execute($values);
}
$pdo->prepare('insert into job_outcomes values (?,?,?)')->execute([batchId(0), hash('xxh128', 'failed'), 'real-retry-id']);
$pdo->query('analyze table job_outcomes')->closeCursor();
$paths = ['PRIMARY', 'failures'];
$samples = [];
for ($round = 0; $round < 22; ++$round) {
    foreach ($paths as $index) {
        $sql = "select retry_id from job_outcomes force index ({$index}) where batch_id=? and retry_id is not null";
        $start = hrtime(true);
        $statement = $pdo->prepare($sql);
        $statement->execute([batchId(0)]);
        check($statement->fetchAll(PDO::FETCH_COLUMN) === ['real-retry-id'], 'Exact sparse result');
        if ($round > 1) {
            $samples[$index][] = (hrtime(true) - $start) / 1e6;
        }
    }
    $paths = array_reverse($paths);
}
echo json_encode(['engine' => $engine, 'case' => 'one_failure_in_100001', 'ms' => $samples], JSON_THROW_ON_ERROR), "\n";
