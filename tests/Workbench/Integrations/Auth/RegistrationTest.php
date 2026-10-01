<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Tests\Workbench\Fixtures\Member;
use Hypervel\Tests\Workbench\Integrations\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function testRegistrationScreenCanBeRendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function testNewUsersCanRegister(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    #[WithEnv('AUTH_MODEL', Member::class)]
    public function testNewUsersAreCreatedWithTheWorkbenchUserModel(): void
    {
        $this->createMembersTable();

        $this->post('/register', [
            'name' => 'Test Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(Member::query()->where('email', 'member@example.com')->firstOrFail());
        $this->assertDatabaseMissing('users', ['email' => 'member@example.com']);
    }

    #[WithEnv('AUTH_MODEL', Member::class)]
    public function testRegistrationChecksEmailUniquenessAgainstTheWorkbenchUserModel(): void
    {
        $this->createMembersTable();

        Member::forceCreate([
            'name' => 'Existing Member',
            'email' => 'member@example.com',
            'password' => 'password',
        ]);

        $this->post('/register', [
            'name' => 'Test Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertSame(1, Member::query()->where('email', 'member@example.com')->count());
    }
}
