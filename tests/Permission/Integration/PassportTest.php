<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Integration;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Hypervel\Passport\SigningKey;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Permission\TestCase;

class PassportTest extends TestCase
{
    /**
     * Configure database signing keys for the OAuth server.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('passport.key_store', 'database');
    }

    public function testClientCredentialsTokenUsesTheClientsPermissions(): void
    {
        $this->setUpPassport();

        (require dirname(__DIR__, 3) . '/src/passport/database/migrations/2016_06_01_000002_create_oauth_access_tokens_table.php')->up();
        (require dirname(__DIR__, 3) . '/src/passport/database/migrations/2016_06_01_000007_create_oauth_signing_keys_table.php')->up();
        SigningKey::factory()->create();

        Route::get('/posts', fn (): string => 'posts')->middleware([
            EnsureClientIsResourceOwner::class,
            PermissionMiddleware::using('edit-posts', 'passport'),
        ]);

        $token = $this->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->testClient->getKey(),
            'client_secret' => $this->testClient->plainSecret,
        ])->assertOk()->json('access_token');

        $this->withToken($token)->get('/posts')->assertForbidden();

        $this->testClient->givePermissionTo('edit-posts');

        $this->withToken($token)->get('/posts')->assertOk()->assertSee('posts');
    }
}
