<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
#[WithMigration]
#[WithConfig('database.default', 'testing')]
class WithMigrationAttributeTest extends TestCase
{
    use DatabaseMigrations;

    #[Test]
    public function itLoadsDefaultMigrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
    }

    #[Test]
    #[WithMigration('cache')]
    public function itLoadsCachesMigrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    #[Test]
    #[WithMigration('notifications')]
    public function itLoadsNotificationsMigrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    #[Test]
    #[WithMigration('queue')]
    public function itLoadsQueueMigrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }

    #[Test]
    #[WithMigration('session')]
    public function itLoadsSessionMigrations(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('password_reset_tokens'));
        $this->assertTrue(Schema::hasTable('cache'));
        $this->assertTrue(Schema::hasTable('cache_locks'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->assertTrue(Schema::hasTable('job_batches'));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertFalse(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasTable('sessions'));
    }
}
