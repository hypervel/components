<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Queue\JobEncryptionTest;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Contracts\Queue\ShouldBeEncrypted;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Foundation\Bus\Dispatchable;
use Hypervel\Support\Facades\Bus;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Queue;
use Hypervel\Support\Str;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Integration\Queue\QueueTestCase;
use Override;

#[WithMigration]
#[WithMigration('queue')]
/**
 * @internal
 * @coversNothing
 */
class JobEncryptionTest extends QueueTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', Str::random(32));
        $app['config']->set('queue.default', 'database');
        $this->driver = 'database';
    }

    #[Override]
    protected function tearDown(): void
    {
        JobEncryptionTestEncryptedJob::$ran = false;
        JobEncryptionTestNonEncryptedJob::$ran = false;

        parent::tearDown();
    }

    public function testEncryptedJobPayloadIsStoredEncrypted()
    {
        Bus::dispatch(new JobEncryptionTestEncryptedJob);

        $this->assertNotEmpty(
            decrypt(json_decode(DB::table('jobs')->first()->payload)->data->command)
        );
    }

    public function testNonEncryptedJobPayloadIsStoredRaw()
    {
        Bus::dispatch(new JobEncryptionTestNonEncryptedJob);

        $this->expectException(DecryptException::class);
        $this->expectExceptionMessage('The payload is invalid');

        $this->assertInstanceOf(
            JobEncryptionTestNonEncryptedJob::class,
            unserialize(json_decode(DB::table('jobs')->first()->payload)->data->command)
        );

        decrypt(json_decode(DB::table('jobs')->first()->payload)->data->command);
    }

    public function testQueueCanProcessEncryptedJob()
    {
        Bus::dispatch(new JobEncryptionTestEncryptedJob);

        Queue::pop()->fire();

        $this->assertTrue(JobEncryptionTestEncryptedJob::$ran);
    }

    public function testQueueCanProcessUnEncryptedJob()
    {
        Bus::dispatch(new JobEncryptionTestNonEncryptedJob);

        Queue::pop()->fire();

        $this->assertTrue(JobEncryptionTestNonEncryptedJob::$ran);
    }
}

class JobEncryptionTestEncryptedJob implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        static::$ran = true;
    }
}

class JobEncryptionTestNonEncryptedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public static bool $ran = false;

    public function handle(): void
    {
        static::$ran = true;
    }
}
