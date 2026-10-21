<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Engine\Channel;
use Hypervel\Http\Request;
use Hypervel\Passport\ClientRepository;
use Hypervel\Passport\Database\Factories\SigningKeyFactory;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\PassportServiceProvider;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Passport\Fixtures\User;

use function Hypervel\Coroutine\parallel;

class BearerTokenCacheTest extends TestCase
{
    /**
     * Register the real OAuth server and guard.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [PassportServiceProvider::class];
    }

    /**
     * Configure shared authentication caches and a one-slot database pool.
     */
    protected function defineEnvironment(Application $app): void
    {
        $keys = (new SigningKeyFactory)->definition();

        $app->make('config')->set([
            'database.default' => 'passport',
            'database.connections.passport' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
                'pool' => [
                    'testing_enabled' => true,
                    'max_connections' => 1,
                    'wait_timeout' => 0.1,
                ],
            ],
            'passport.private_key' => $keys['private_key'],
            'passport.public_key' => $keys['public_key'],
            'passport.cache.enabled' => true,
            'passport.cache.store' => 'passport-file',
            'cache.stores.passport-file' => [
                'driver' => 'file',
                'path' => $app->storagePath('framework/cache/data/passport-bearer'),
            ],
            'cache.serializable_classes' => [User::class],
            'auth.providers.users.model' => User::class,
            'auth.providers.users.cache.enabled' => true,
            'auth.providers.users.cache.store' => 'passport-file',
        ]);
    }

    /**
     * Create a personal access token without retaining the pool's only connection.
     */
    protected function createToken(): string
    {
        $schema = DB::connection()->getSchemaBuilder();
        $schema->create('users', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php')->up();
        (require __DIR__ . '/../../src/passport/database/migrations/2016_06_01_000004_create_oauth_clients_table.php')->up();

        $this->app->make(ClientRepository::class)->createPersonalAccessGrantClient('Test', 'users');

        return User::forceCreate(['name' => 'Jane'])->createToken('Test')->accessToken;
    }

    /**
     * Remove the shared cache owned by this test.
     */
    protected function tearDown(): void
    {
        try {
            $this->app->make('cache')->store('passport-file')->flush();
        } finally {
            parent::tearDown();
        }
    }

    public function testWarmBearerAuthenticationDoesNotBorrowFromASaturatedDatabasePool(): void
    {
        [$token] = parallel([$this->createToken(...)]);

        Route::get('/cached-user', fn (Request $request): string => $request->user()->name)
            ->middleware(['auth:passport', CheckToken::class]);

        $this->withToken($token)->get('/cached-user')->assertOk()->assertSee('Jane');

        $ready = new Channel(1);
        $release = new Channel(1);

        parallel([
            static function () use ($ready, $release): void {
                $connection = DB::connection('passport');
                $connection->beginTransaction();

                try {
                    $ready->push(true);
                    $release->pop(2);
                } finally {
                    $connection->rollBack();
                }
            },
            function () use ($ready, $release): void {
                $ready->pop(2);

                try {
                    $this->get('/cached-user')->assertOk()->assertSee('Jane');
                } finally {
                    $release->push(true);
                }
            },
        ]);
    }
}
