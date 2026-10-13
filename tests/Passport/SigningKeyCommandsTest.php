<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Console\Scheduling\Schedule;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\KeyGenerator;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Passport\ServerFactory;
use Hypervel\Passport\ServerKey;
use Hypervel\Passport\SigningKey;
use Hypervel\Passport\SigningKeyRepository;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class SigningKeyCommandsTest extends TestCase
{
    /**
     * Boot the real Passport provider to exercise command and schedule registration.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Configure database keys with a shared lock store.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set([
            'passport.key_store' => 'database',
            'cache.default' => 'passport-command-file',
            'cache.stores.passport-command-file' => [
                'driver' => 'file', 'path' => $app->storagePath('framework/cache/data/passport-commands'),
            ],
        ]);
    }

    /**
     * Create only the tables needed by key commands.
     */
    protected function setUpInCoroutine(): void
    {
        foreach ([
            '2016_06_01_000002_create_oauth_access_tokens_table.php',
            '2016_06_01_000007_create_oauth_signing_keys_table.php',
        ] as $migration) {
            (require __DIR__ . '/../../src/passport/database/migrations/' . $migration)->up();
        }
    }

    /**
     * Remove cache files owned by this test class.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-command-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testDatabaseKeyCommandsProvisionListRotateAndRevoke(): void
    {
        $material = (new SigningKeyFactory)->definition();
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->once()->andReturn([
            'public' => $material['public_key'], 'private' => $material['private_key'],
        ]);
        $this->app->instance(KeyGenerator::class, $generator);
        $this->artisan('passport:keys', ['--length' => 2048])->expectsOutputToContain('Encryption keys generated successfully.')->assertSuccessful();
        $initial = SigningKey::sole();
        $identifier = ParsedKey::fromPublicKey($initial->public_key)->getKeyId();
        $this->artisan('passport:keys')->expectsOutputToContain('Use passport:keys:rotate')->assertFailed();
        $this->artisan('passport:keys:list')->expectsTable(
            ['Key ID', 'Created (UTC)', 'Activates (UTC)', 'Retires (UTC)', 'Status'],
            [[$identifier, $initial->created_at->toDateTimeString(), $initial->activates_at->toDateTimeString(), null, 'Signing']],
        )->assertSuccessful();

        $repository = m::mock(SigningKeyRepository::class);
        $waiting = new SigningKey;
        $waiting->forceFill(['activates_at' => CarbonImmutable::now()->addDay()]);
        $repository->shouldReceive('rotate')->with(2048)->once()->andReturn($waiting);
        $repository->shouldReceive('revoke')->with($identifier)->once();
        $repository->shouldReceive('rotateDueKeys')->with(4096)->once();
        $this->app->instance(SigningKeyRepository::class, $repository);

        $this->artisan('passport:keys:rotate', ['--length' => 2048])->expectsOutputToContain('Signing key published.')->assertSuccessful();
        $this->artisan('passport:keys:rotate', ['--due' => true])->expectsOutputToContain('Signing key rotation check completed.')->assertSuccessful();
        $this->artisan('passport:keys:revoke', ['kid' => $identifier])->expectsOutputToContain('Signing key revoked.')->assertSuccessful();
    }

    public function testImportCommandCarriesTheExplicitRetentionFloor(): void
    {
        $material = (new SigningKeyFactory)->definition();
        $factory = m::mock(ServerFactory::class);
        $factory->shouldReceive('fileKeys')->once()->andReturn([new ServerKey($material['public_key'], $material['private_key'])]);
        $this->app->instance(ServerFactory::class, $factory);
        $this->artisan('passport:keys:import', ['--retain-until' => '2030-01-01T00:00:00Z'])
            ->expectsOutputToContain('Signing keys imported.')->assertSuccessful();

        $this->assertSame('2030-01-01 00:00:00', SigningKey::sole()->retain_until->toDateTimeString());
    }

    public function testProviderSchedulesOneDailyRotationCheckWithOverlapProtection(): void
    {
        $events = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn ($event): bool => str_contains($event->command ?? '', 'passport:keys:rotate'),
        ));

        $this->assertCount(1, $events);
        $this->assertStringContainsString('--due', $events[0]->command);
        $this->assertSame('0 0 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
