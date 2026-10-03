<?php

declare(strict_types=1);

namespace Hypervel\Tests\Inertia\DevTools;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Request;
use Hypervel\Session\SessionId;
use Hypervel\Support\Facades\Gate;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Str;
use Hypervel\Tests\Inertia\TestCase;

class FlashDataTest extends TestCase
{
    use InteractsWithDevToolsStorage;

    /**
     * Define the test environment.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $config = $app->make('config');

        // The entry endpoints run the `web` middleware group, which encrypts cookies.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $config->set('inertia.devtools.enabled', true);
        $config->set('inertia.devtools.gate', 'viewInertiaDevtools');
    }

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->bindEntriesRepository();
        Gate::define('viewInertiaDevtools', fn (?Authenticatable $user = null): bool => true);
    }

    /**
     * Clean up the test environment.
     */
    protected function tearDown(): void
    {
        $this->clearDevToolsStorage();

        parent::tearDown();
    }

    public function testValidationErrorsSurviveTheEntryRequestRacingTheRedirect(): void
    {
        Route::middleware('web')->post('/users', fn (Request $request): array => $request->validate(['name' => 'required']));
        Route::middleware('web')->get('/users/create', fn (): array => session('errors')?->get('name') ?? []);

        $this->post('/users')->assertStatus(302);

        // The extension fetches the entry the moment the failed POST responds, so this lands
        // between the redirect and the request the browser makes to follow it.
        $this->getJson('/_inertia/devtools/entries')->assertOk();
        $this->getJson('/_inertia/devtools/entries/' . $this->savedEntryId())->assertOk();

        $this->get('/users/create')->assertSee('The name field is required.');
    }

    public function testFlashedDataIsStillReadOnceByTheApp(): void
    {
        Route::middleware('web')->get('/app-page', fn (): string => (string) session('status'));

        $this->session(['status' => 'saved', '_flash' => ['old' => ['status'], 'new' => []]]);

        $this->getJson('/_inertia/devtools/entries')->assertOk();

        $this->get('/app-page')->assertSee('saved');
        $this->get('/app-page')->assertDontSee('saved');
    }

    public function testEntryRequestsDoNotOverwriteSessionDataSavedByConcurrentRequests(): void
    {
        $session = $this->app->make('session')->driver();
        $sessionId = SessionId::generate();
        $token = Str::random(40);
        $session->getHandler()->write($sessionId, json_encode(['_token' => $token, 'cart' => 'original']));
        $updated = json_encode(['_token' => $token, 'cart' => 'updated']);

        // The gate runs inside the entry request after its session has started, which is when
        // an application request saves newer session data here.
        Gate::define('viewInertiaDevtools', function (?Authenticatable $user = null) use ($session, $sessionId, $updated): bool {
            $session->getHandler()->write($sessionId, $updated);

            return true;
        });

        $this->withCookie($session->getName(), $sessionId)
            ->get('/_inertia/devtools/entries')
            ->assertOk();

        $this->assertSame($updated, $session->getHandler()->read($sessionId));
    }

    /**
     * Save an entry and return its id.
     */
    protected function savedEntryId(): string
    {
        $id = (string) Str::ulid();

        $this->repo->save($id, ['__meta' => ['id' => $id]]);

        return $id;
    }
}
