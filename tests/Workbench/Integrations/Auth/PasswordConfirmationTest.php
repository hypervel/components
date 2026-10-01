<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Routing\Router;
use Hypervel\Tests\Workbench\Integrations\TestCase;
use Workbench\Database\Factories\UserFactory;

class PasswordConfirmationTest extends TestCase
{
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

    public function testPasswordIsNotConfirmedWithInvalidPassword(): void
    {
        $user = UserFactory::new()->create();

        $response = $this->actingAs($user)->post('/confirm-password', [
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors();
    }
}
