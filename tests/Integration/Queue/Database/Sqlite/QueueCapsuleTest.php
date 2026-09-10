<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\Database\Sqlite;

use Hypervel\Container\Container;
use Hypervel\Database\Capsule\Manager as DatabaseCapsule;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Queue\Capsule\Manager as QueueCapsule;
use Hypervel\Tests\TestCase;

class QueueCapsuleTest extends TestCase
{
    public function testStandaloneCapsulesShareConfigurationAndStoreOnTheForwardedQueue(): void
    {
        $container = new Container;
        $database = new DatabaseCapsule($container);
        $database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $database->getConnection();
        $connection->getSchemaBuilder()->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $container->instance('db', $database->getDatabaseManager());
        $queue = new QueueCapsule($container);
        $queue->addConnection([
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'reports',
        ]);
        $queue->forward('reports', 'processing');

        $id = $queue->getConnection()->pushRaw('{"job":"example","data":[]}');

        $this->assertSame('processing', $connection->table('jobs')->find($id)->queue);
        $this->assertSame(1, $queue->getConnection()->size());
    }
}
