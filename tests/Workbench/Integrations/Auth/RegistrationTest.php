<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Integrations\Auth;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\WithEnv;
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

    #[WithEnv('AUTH_MODEL', RegisteredMember::class)]
    public function testNewUsersAreCreatedWithTheWorkbenchUserModel(): void
    {
        $this->createMembersTable();

        $this->post('/register', [
            'name' => 'Test Member',
            'email' => 'member@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(RegisteredMember::query()->where('email', 'member@example.com')->firstOrFail());
        $this->assertDatabaseMissing('users', ['email' => 'member@example.com']);
    }

    #[WithEnv('AUTH_MODEL', RegisteredMember::class)]
    public function testRegistrationChecksEmailUniquenessAgainstTheWorkbenchUserModel(): void
    {
        $this->createMembersTable();

        RegisteredMember::forceCreate([
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
        $this->assertSame(1, RegisteredMember::query()->where('email', 'member@example.com')->count());
    }

    /**
     * Create the table for the custom user model.
     */
    private function createMembersTable(): void
    {
        Schema::create('workbench_members', static function (Blueprint $table): void {
            $table->id('member_id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }
}

class RegisteredMember extends Authenticatable
{
    protected ?string $table = 'workbench_members';

    protected string $primaryKey = 'member_id';
}
