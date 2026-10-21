<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Workbench\Fixtures\MakesBrowserRequests;
use Hypervel\Tests\Workbench\Fixtures\Member;
use Hypervel\Tests\Workbench\Integrations\TestCase;
use Hypervel\Workbench\WorkbenchServiceProvider;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

class PasswordConfirmationTest extends TestCase
{
    use MakesBrowserRequests;
    use RefreshDatabase;

    public function testConfirmPasswordScreenCanBeRendered(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->get('/confirm-password');

        $response->assertStatus(200);
    }

    public function testPasswordCanBeConfirmed(): void
    {
        $this->app->make(Router::class)
            ->middleware(['web', 'auth', 'password.confirm'])
            ->get('/secure', fn (): string => 'Secure area');

        $user = UserFactory::new()->create();

        $this->actingAs($user)->get('/secure')->assertRedirect('/confirm-password');

        $response = $this->post('/confirm-password', [
            'password' => 'password',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->get('/secure')->assertOk()->assertSee('Secure area');
    }

    public function testPasswordIsConfirmedAgainstTheDefaultGuard(): void
    {
        $this->useMembersGuard();

        $this->app->make(Router::class)
            ->middleware(['web', 'auth', 'password.confirm'])
            ->get('/secure', fn (): string => 'Secure area');

        $member = Member::forceCreate([
            'name' => 'Member',
            'email' => 'member@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($member)->get('/secure')->assertRedirect('/confirm-password');

        $this->post('/confirm-password', [
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->get('/secure')->assertOk()->assertSee('Secure area');
    }

    public function testSwitchingPreviewUsersRequiresConfirmingAgain(): void
    {
        [$first, $second] = UserFactory::new()->count(2)->create();

        $response = $this->withSessionCookieFrom($this->confirmPasswordAsPreviewUser($first))
            ->get("/_workbench/login/{$second->getKey()}/web")
            ->assertRedirect('/');

        $this->withSessionCookieFrom($response)->get('/secure')->assertRedirect('/confirm-password');
    }

    public function testLoggingInAfterPreviewLogoutRequiresConfirmingAgain(): void
    {
        [$first, $second] = UserFactory::new()->count(2)->create();

        $response = $this->withSessionCookieFrom($this->confirmPasswordAsPreviewUser($first))
            ->get('/_workbench/logout/web')
            ->assertRedirect('/');

        $response = $this->withSessionCookieFrom($response)
            ->post('/login', ['email' => $second->email, 'password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->withSessionCookieFrom($response)->get('/secure')->assertRedirect('/confirm-password');
    }

    public function testPasswordIsNotConfirmedWithInvalidPassword(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }

    /**
     * Log in through the preview helper and confirm the password, returning the confirmed response.
     */
    private function confirmPasswordAsPreviewUser(User $user): TestResponse
    {
        $this->app->register(WorkbenchServiceProvider::class);

        $this->app->make(Router::class)
            ->middleware(['web', 'auth', 'password.confirm'])
            ->get('/secure', fn (): string => 'Secure area');

        $response = $this->get("/_workbench/login/{$user->getKey()}/web")->assertRedirect('/');

        $response = $this->withSessionCookieFrom($response)
            ->post('/confirm-password', ['password' => 'password'])
            ->assertSessionHasNoErrors();

        return $this->withSessionCookieFrom($response)->get('/secure')->assertOk();
    }
}
