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
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;

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

    #[WithConfig('app.timezone', 'Asia/Tokyo')]
    public function testDatabaseKeyCommandsProvisionListRotateAndRevoke(): void
    {
        $now = CarbonImmutable::now()->startOfSecond();
        CarbonImmutable::setTestNow($now);
        $material = (new SigningKeyFactory)->definition();
        $generator = m::mock(KeyGenerator::class);
        $generator->shouldReceive('generate')->with(2048)->times(3)->andReturn(
            ['public' => $material['public_key'], 'private' => $material['private_key']],
            (new KeyGenerator)->generate(2048),
            (new KeyGenerator)->generate(2048),
        );
        $this->app->instance(KeyGenerator::class, $generator);
        $this->artisan('passport:keys', ['--length' => 2048])->expectsOutputToContain('Encryption keys generated successfully.')->assertSuccessful();
        $initial = SigningKey::sole();
        $initial->forceFill(['max_token_lifetime' => 3 * 86400])->save();
        $identifier = ParsedKey::fromPublicKey($initial->public_key)->getKeyId();
        $this->artisan('passport:keys')->expectsOutputToContain('Use passport:keys:rotate')->assertFailed();
        $activation = $now->addSeconds(config()->integer('passport.key_activation_delay'));
        $this->artisan('passport:keys:rotate', ['--length' => 2048])
            ->expectsOutputToContain('Signing key published. It activates at ' . $activation->utc()->toDateTimeString() . ' UTC.')
            ->assertSuccessful();
        $this->artisan('passport:keys:rotate', ['--length' => 2048])
            ->expectsOutputToContain('Signing key is already waiting.')->assertSuccessful();
        $repository = $this->app->make(SigningKeyRepository::class);
        $active = $repository->all()->first();
        CarbonImmutable::setTestNow($activation->addSecond());
        $this->artisan('passport:keys:rotate', ['--length' => 2048])->assertSuccessful();
        $waiting = $repository->all()->first();

        $this->artisan('passport:keys:list')->expectsTable(
            ['Key ID', 'Created (UTC)', 'Activates (UTC)', 'Retires (UTC)', 'Status'],
            [
                [ParsedKey::fromPublicKey($waiting->public_key)->getKeyId(), $activation->addSecond()->utc()->toDateTimeString(), $waiting->activates_at->utc()->toDateTimeString(), null, 'Waiting'],
                [ParsedKey::fromPublicKey($active->public_key)->getKeyId(), $now->utc()->toDateTimeString(), $activation->utc()->toDateTimeString(), $waiting->activates_at->utc()->toDateTimeString(), 'Signing'],
                [$identifier, $now->utc()->toDateTimeString(), $now->utc()->toDateTimeString(), $activation->addDays(3)->utc()->toDateTimeString(), 'Verify only'],
            ],
        )->assertSuccessful();
        $this->artisan('passport:keys:rotate', ['--due' => true])->expectsOutputToContain('Signing key rotation check completed.')->assertSuccessful();
        $this->artisan('passport:keys:revoke', ['kid' => $identifier])->expectsOutputToContain('Signing key revoked.')->assertSuccessful();
        $this->assertNull($initial->fresh());
    }

    #[WithConfig('app.timezone', 'Asia/Tokyo')]
    public function testImportCommandCarriesTheExplicitRetentionFloor(): void
    {
        $material = (new SigningKeyFactory)->definition();
        $factory = m::mock(ServerFactory::class);
        $factory->shouldReceive('fileKeys')->once()->andReturn([new ServerKey($material['public_key'], $material['private_key'])]);
        $this->app->instance(ServerFactory::class, $factory);
        $this->artisan('passport:keys:import', ['--retain-until' => '2030-01-01T00:00:00Z'])
            ->expectsOutputToContain('Signing keys imported.')->assertSuccessful();

        $this->assertSame(CarbonImmutable::parse('2030-01-01T00:00:00Z')->getTimestamp(), SigningKey::sole()->retain_until->getTimestamp());
    }

    public function testProvisioningReportsWhenAnotherProcessAlreadyCreatedTheKeys(): void
    {
        $repository = m::mock(SigningKeyRepository::class);
        $repository->shouldReceive('create')->with(2048)->once()->andReturnNull();
        $this->app->instance(SigningKeyRepository::class, $repository);

        $this->artisan('passport:keys', ['--length' => 2048])
            ->expectsOutputToContain('Signing keys already exist.')->assertFailed();
    }

    public function testRevokingAnUnknownKeyFails(): void
    {
        $this->artisan('passport:keys:revoke', ['kid' => 'unknown'])
            ->expectsOutputToContain('Signing key not found.')->assertFailed();
    }

    #[TestWith(['passport:keys:rotate', []])]
    #[TestWith(['passport:keys:revoke', ['kid' => 'key']])]
    public function testDatabaseManagementCommandsRejectTheFilesStore(string $command, array $parameters): void
    {
        config(['passport.key_store' => 'files']);
        $repository = m::mock(SigningKeyRepository::class);
        $repository->shouldNotReceive('rotate');
        $repository->shouldNotReceive('revoke');
        $this->app->instance(SigningKeyRepository::class, $repository);

        $this->artisan($command, $parameters)->expectsOutputToContain('Rotate file keys manually using passport.verification_keys.')->assertFailed();
        $this->assertSame(0, SigningKey::count());
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
