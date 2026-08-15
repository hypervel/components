<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Session\Database\Sqlite;

use Hypervel\Auth\SessionGuard;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Hypervel\Session\SessionId;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Exceptions;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Session;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Testbench\TestCase;
use Override;
use RuntimeException;
use Workbench\App\Models\User;

#[RequiresDatabase('sqlite')]
#[WithMigration('session')]
class UserSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Override]
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set([
            'app.key' => '12345678901234567890123456789012',
            'auth.guards.admin' => [
                'driver' => 'session',
                'provider' => 'admins',
                'passwords' => null,
                'password_timeout' => null,
                'remember' => null,
            ],
            'auth.providers.admins' => [
                'driver' => 'eloquent',
                'model' => User::class,
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 300,
                    'prefix' => 'auth_users',
                    'tags' => null,
                ],
            ],
            'auth.providers.users.model' => User::class,
            'hashing.bcrypt.rounds' => 4,
            'session.driver' => 'database',
            'session.lottery' => [0, 100],
        ]);
    }

    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->post('/session-lifecycle/login/{userId}', function (Request $request, string $userId) {
            Auth::login(User::query()->findOrFail($userId), $request->boolean('remember'));

            return response()->json(['session_id' => $request->session()->getId()]);
        })->middleware('web');

        $router->post('/session-lifecycle/admin-login/{userId}', function (Request $request, string $userId) {
            Auth::shouldUse('admin');
            Auth::login(User::query()->findOrFail($userId));

            return response()->json(['session_id' => $request->session()->getId()]);
        })->middleware('web');

        $router->post('/session-lifecycle/invalidate', function (Request $request) {
            $oldSessionId = $request->session()->getId();
            $request->session()->invalidate();

            return response()->json([
                'old_session_id' => $oldSessionId,
                'session_id' => $request->session()->getId(),
            ]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/invalidate-then-throw', function (Request $request): never {
            $request->session()->invalidate();

            throw new RuntimeException('Route failed after invalidating the session.');
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/invalidate-and-save', function (Request $request) {
            $request->session()->invalidate();
            $request->session()->save();

            return response()->json(['session_id' => $request->session()->getId()]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/invalidate-and-login', function (Request $request) {
            $user = $request->user();

            $request->session()->invalidate();
            Auth::login($user);

            return response()->json(['session_id' => $request->session()->getId()]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/invalidate-managed', function (Request $request) {
            $user = $request->user();
            $sessionId = $request->session()->getId();

            return response()->json([
                'invalidated' => Session::forUser($user)->invalidate($sessionId),
                'session_id' => $request->session()->getId(),
            ]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/manage/{userId}', function (Request $request, string $userId) {
            $currentSessionId = $request->session()->getId();
            $deleted = Session::forUser(User::query()->findOrFail($userId))->invalidateAll();

            return response()->json([
                'authenticated_user_id' => $request->user()->getAuthIdentifier(),
                'deleted' => $deleted,
                'session_id' => $request->session()->getId(),
                'session_id_unchanged' => $currentSessionId === $request->session()->getId(),
            ]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/manage-providers', function (Request $request) {
            $user = $request->user();
            $selectedGuardBefore = Auth::getDefaultDriver();
            $webSessionIds = Session::forUser($user)->all()->pluck('id')->all();
            $adminSessions = Session::forUser($user, 'admin');
            $adminSessionIds = $adminSessions->all()->pluck('id')->all();
            $invalidated = $adminSessions->invalidate((string) $request->input('admin_session_id'));

            return response()->json([
                'admin_session_ids' => $adminSessionIds,
                'invalidated' => $invalidated,
                'selected_guard_after' => Auth::getDefaultDriver(),
                'selected_guard_before' => $selectedGuardBefore,
                'web_session_ids' => $webSessionIds,
            ]);
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/logout', function () {
            Auth::logout();

            return response()->noContent();
        })->middleware(['web', 'auth']);

        $router->post('/session-lifecycle/logout-and-invalidate-all', function (Request $request) {
            $user = $request->user();

            Auth::logout();

            return response()->json([
                'deleted' => Session::forUser($user)->invalidateAll(),
                'session_id' => $request->session()->getId(),
            ]);
        })->middleware(['web', 'auth']);

        $router->get('/session-lifecycle/current-user', function (Request $request) {
            return response()->json([
                'authenticated_user_id' => Auth::id(),
                'session_id' => $request->session()->getId(),
            ]);
        })->middleware('web');
    }

    public function testDirectInvalidationCannotResurrectTheDeletedSessionOrOwnItsReplacement(): void
    {
        $user = $this->createUser('direct@example.com');
        $oldSessionId = $this->login($user);

        $response = $this->post('/session-lifecycle/invalidate')->assertOk();
        $replacementSessionId = $response->json('session_id');

        $this->assertIsString($replacementSessionId);
        $this->assertNotSame($oldSessionId, $replacementSessionId);
        $this->assertUnownedReplacement($oldSessionId, $replacementSessionId);
    }

    public function testExceptionResponseSaveKeepsTheReplacementUnowned(): void
    {
        $user = $this->createUser('exception@example.com');
        $oldSessionId = $this->login($user);

        Exceptions::fake();

        $this->post('/session-lifecycle/invalidate-then-throw')
            ->assertInternalServerError();

        Exceptions::assertReported(
            fn (RuntimeException $exception): bool => $exception->getMessage()
                === 'Route failed after invalidating the session.'
        );

        $replacement = DB::table('sessions')->sole();

        $this->assertNotSame($oldSessionId, $replacement->id);
        $this->assertUnownedReplacement($oldSessionId, $replacement->id);
    }

    public function testApplicationSaveAndMiddlewareSaveKeepTheReplacementUnowned(): void
    {
        $user = $this->createUser('repeated-save@example.com');
        $oldSessionId = $this->login($user);

        $response = $this->post('/session-lifecycle/invalidate-and-save')->assertOk();
        $replacementSessionId = $response->json('session_id');

        $this->assertIsString($replacementSessionId);
        $this->assertUnownedReplacement($oldSessionId, $replacementSessionId);
    }

    public function testLoginAfterInvalidationRotatesBeyondTheUnownedReplacementAndTracksTheFreshSession(): void
    {
        $user = $this->createUser('login-again@example.com');
        $oldSessionId = $this->login($user);

        $response = $this->post('/session-lifecycle/invalidate-and-login')->assertOk();
        $freshSessionId = $response->json('session_id');

        $this->assertIsString($freshSessionId);
        $this->assertNotSame($oldSessionId, $freshSessionId);
        $this->assertFalse(DB::table('sessions')->where('id', $oldSessionId)->exists());
        $this->assertSame(
            (string) $user->getAuthIdentifier(),
            DB::table('sessions')->where('id', $freshSessionId)->value('user_id'),
        );
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', $freshSessionId)->value('auth_provider'),
        );
    }

    public function testRememberCookieReauthenticatesIntoAFreshTrackedSessionAfterInvalidation(): void
    {
        $user = $this->createUser('remember@example.com');
        $loginResponse = $this->post('/session-lifecycle/login/' . $user->getKey(), [
            'remember' => true,
        ])->assertOk();
        $oldSessionId = $loginResponse->json('session_id');

        $guard = Auth::guard();
        $this->assertInstanceOf(SessionGuard::class, $guard);

        $recaller = $loginResponse->getCookie($guard->getRecallerName());

        $this->assertIsString($oldSessionId);
        $this->assertNotNull($recaller);

        $this->withCookie($this->sessionCookieName(), $oldSessionId);
        $this->withCookie($guard->getRecallerName(), (string) $recaller->getValue());

        $invalidateResponse = $this->post('/session-lifecycle/invalidate')->assertOk();
        $unownedSessionId = $invalidateResponse->json('session_id');

        $this->assertIsString($unownedSessionId);

        // HTTP tests synchronize durable Auth context to their parent coroutine.
        // Clear that test state so the next request must use the recaller cookie.
        $guard->forgetUser();
        $this->withCookie($this->sessionCookieName(), $unownedSessionId);

        $currentResponse = $this->get('/session-lifecycle/current-user')->assertOk();
        $freshSessionId = $currentResponse->json('session_id');

        $this->assertSame($user->getAuthIdentifier(), $currentResponse->json('authenticated_user_id'));
        $this->assertIsString($freshSessionId);
        $this->assertNotSame($unownedSessionId, $freshSessionId);
        $this->assertFalse(DB::table('sessions')->where('id', $oldSessionId)->exists());
        $this->assertFalse(DB::table('sessions')->where('id', $unownedSessionId)->exists());
        $this->assertSame(
            (string) $user->getAuthIdentifier(),
            DB::table('sessions')->where('id', $freshSessionId)->value('user_id'),
        );
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', $freshSessionId)->value('auth_provider'),
        );
    }

    public function testDifferentProvidersWithTheSameUserIdentifierAreIsolatedWithoutChangingTheSelectedGuard(): void
    {
        $user = $this->createUser('provider-isolation@example.com');
        $webSessionId = $this->login($user);

        $this->withCookie($this->sessionCookieName(), SessionId::generate());
        $adminResponse = $this->post(
            '/session-lifecycle/admin-login/' . $user->getKey(),
        )->assertOk();
        $adminSessionId = $adminResponse->json('session_id');

        $this->assertIsString($adminSessionId);
        $this->assertNotSame($webSessionId, $adminSessionId);
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', $webSessionId)->value('auth_provider'),
        );
        $this->assertSame(
            'admins',
            DB::table('sessions')->where('id', $adminSessionId)->value('auth_provider'),
        );

        $this->withCookie($this->sessionCookieName(), $webSessionId);
        $response = $this->post('/session-lifecycle/manage-providers', [
            'admin_session_id' => $adminSessionId,
        ])->assertOk();

        $this->assertSame([$webSessionId], $response->json('web_session_ids'));
        $this->assertSame([$adminSessionId], $response->json('admin_session_ids'));
        $this->assertTrue($response->json('invalidated'));
        $this->assertSame('web', $response->json('selected_guard_before'));
        $this->assertSame('web', $response->json('selected_guard_after'));
        $this->assertTrue(DB::table('sessions')->where('id', $webSessionId)->exists());
        $this->assertFalse(DB::table('sessions')->where('id', $adminSessionId)->exists());
    }

    public function testManagingAnotherUsersSessionsLeavesTheAdministratorUntouched(): void
    {
        $administrator = $this->createUser('administrator@example.com');
        $target = $this->createUser('target@example.com');
        $administratorSessionId = $this->login($administrator);
        $targetSessionIds = [SessionId::generate(), SessionId::generate()];

        foreach ($targetSessionIds as $targetSessionId) {
            DB::table('sessions')->insert([
                'id' => $targetSessionId,
                'auth_provider' => 'users',
                'user_id' => (string) $target->getAuthIdentifier(),
                'payload' => base64_encode(serialize([])),
                'last_activity' => now()->getTimestamp(),
            ]);
        }

        $response = $this->post('/session-lifecycle/manage/' . $target->getKey())->assertOk();

        $this->assertSame($administrator->getAuthIdentifier(), $response->json('authenticated_user_id'));
        $this->assertSame(2, $response->json('deleted'));
        $this->assertSame($administratorSessionId, $response->json('session_id'));
        $this->assertTrue($response->json('session_id_unchanged'));
        $this->assertSame(
            (string) $administrator->getAuthIdentifier(),
            DB::table('sessions')->where('id', $administratorSessionId)->value('user_id'),
        );
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', $administratorSessionId)->value('auth_provider'),
        );
        $this->assertFalse(DB::table('sessions')->whereIn('id', $targetSessionIds)->exists());
    }

    public function testManagedInvalidationDoesNotMutateAuthenticationCredentials(): void
    {
        $user = $this->createUser('managed@example.com', 'known-remember-token');
        $password = $user->password;
        $rememberToken = $user->getRememberToken();
        $oldSessionId = $this->login($user);

        $response = $this->post('/session-lifecycle/invalidate-managed')->assertOk();
        $replacementSessionId = $response->json('session_id');

        $this->assertTrue($response->json('invalidated'));
        $this->assertIsString($replacementSessionId);
        $this->assertUnownedReplacement($oldSessionId, $replacementSessionId);

        $user->refresh();

        $this->assertSame($password, $user->password);
        $this->assertSame($rememberToken, $user->getRememberToken());
    }

    public function testAuthenticationLogoutCyclesTheRememberTokenWithoutDeletingTheSession(): void
    {
        $user = $this->createUser('logout@example.com', 'known-remember-token');
        $password = $user->password;
        $rememberToken = $user->getRememberToken();
        $sessionId = $this->login($user);

        $this->post('/session-lifecycle/logout')->assertNoContent();

        $user->refresh();

        $this->assertSame($password, $user->password);
        $this->assertNotSame($rememberToken, $user->getRememberToken());
        $this->assertSame(
            (string) $user->getAuthIdentifier(),
            DB::table('sessions')->where('id', $sessionId)->value('user_id'),
        );
        $this->assertSame(
            'users',
            DB::table('sessions')->where('id', $sessionId)->value('auth_provider'),
        );
    }

    public function testLogoutThenBulkInvalidationRotatesFromProvenStorageOwnership(): void
    {
        $user = $this->createUser('logout-all@example.com', 'known-remember-token');
        $oldSessionId = $this->login($user);

        $response = $this->post('/session-lifecycle/logout-and-invalidate-all')->assertOk();
        $replacementSessionId = $response->json('session_id');

        $this->assertSame(1, $response->json('deleted'));
        $this->assertIsString($replacementSessionId);
        $this->assertNotSame($oldSessionId, $replacementSessionId);
        $this->assertUnownedReplacement($oldSessionId, $replacementSessionId);
    }

    private function createUser(string $email, ?string $rememberToken = null): User
    {
        return User::query()->forceCreate([
            'name' => 'Session User',
            'email' => $email,
            'password' => Hash::make('password'),
            'remember_token' => $rememberToken,
        ]);
    }

    private function login(User $user): string
    {
        $response = $this->post('/session-lifecycle/login/' . $user->getKey())->assertOk();
        $sessionId = $response->json('session_id');

        $this->assertIsString($sessionId);
        $this->withCookie($this->sessionCookieName(), $sessionId);

        return $sessionId;
    }

    private function sessionCookieName(): string
    {
        return $this->app->make('config')->string('session.cookie');
    }

    private function assertUnownedReplacement(string $oldSessionId, string $replacementSessionId): void
    {
        $this->assertFalse(DB::table('sessions')->where('id', $oldSessionId)->exists());
        $this->assertTrue(DB::table('sessions')->where('id', $replacementSessionId)->exists());
        $this->assertNull(DB::table('sessions')->where('id', $replacementSessionId)->value('auth_provider'));
        $this->assertNull(DB::table('sessions')->where('id', $replacementSessionId)->value('user_id'));
    }
}
