<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Bootstrap
|--------------------------------------------------------------------------
|
| This bootstrap file loads the .env file (if present) before PHPUnit runs,
| allowing local environment configuration for integration tests.
|
*/

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env file if it exists (for local development)
$dotenvPath = dirname(__DIR__);
if (file_exists($dotenvPath . '/.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable($dotenvPath);
    $dotenv->load();
}
