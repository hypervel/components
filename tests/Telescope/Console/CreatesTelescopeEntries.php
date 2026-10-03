<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Telescope\Database\Factories\EntryModelFactory;
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\Storage\EntryModel;

trait CreatesTelescopeEntries
{
    /**
     * Create an entry of the given type.
     */
    protected function createEntry(string $type, array $content = [], array $attributes = []): EntryModel
    {
        return EntryModelFactory::new()->create($attributes + [
            'type' => $type,
            'content' => $content + ['hostname' => 'localhost'],
        ]);
    }

    /**
     * Create a request entry.
     */
    protected function createRequest(array $content = [], array $attributes = []): EntryModel
    {
        return $this->createEntry(EntryType::REQUEST, $content + [
            'method' => 'GET', 'uri' => '/test', 'response_status' => 200,
            'duration' => 50, 'memory' => 8, 'ip_address' => '127.0.0.1',
            'middleware' => [], 'payload' => [], 'response' => [],
        ], $attributes);
    }

    /**
     * Create a query entry.
     */
    protected function createQuery(array $content = [], array $attributes = []): EntryModel
    {
        return $this->createEntry(EntryType::QUERY, $content + [
            'sql' => 'select 1', 'time' => 1.0, 'connection' => 'testing', 'slow' => false, 'hash' => 'h1',
        ], $attributes);
    }

    /**
     * Create an exception entry.
     */
    protected function createException(array $content = [], array $attributes = []): EntryModel
    {
        return $this->createEntry(EntryType::EXCEPTION, $content + [
            'class' => 'RuntimeException', 'message' => 'Test', 'file' => 'test.php',
            'line' => 1, 'trace' => [], 'occurrences' => 1,
        ], $attributes);
    }
}
