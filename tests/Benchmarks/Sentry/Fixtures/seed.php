<?php

declare(strict_types=1);

// Create the benchmark app's SQLite table with 100 rows.
$path = $argv[1];

if (file_exists($path)) {
    unlink($path);
}

$pdo = new PDO('sqlite:' . $path);
$pdo->exec('CREATE TABLE bench_items (id INTEGER PRIMARY KEY, name TEXT NOT NULL)');
$insert = $pdo->prepare('INSERT INTO bench_items (id, name) VALUES (?, ?)');

for ($id = 1; $id <= 100; ++$id) {
    $insert->execute([$id, 'item-' . $id]);
}

echo $pdo->query('SELECT COUNT(*) FROM bench_items')->fetchColumn(), PHP_EOL;
