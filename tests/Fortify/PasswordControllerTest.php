<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Contracts\Auth\PasswordBroker;
use Hypervel\Fortify\Contracts\UpdatesUserPasswords;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Password;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Fortify\Fixtures\UpdateUserPassword;
use Hypervel\Validation\ValidationException;
use Mockery as m;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

#[WithMigration]
class PasswordControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testPasswordsCanBeUpdated(): void
    {
        $user = $this->createUser();

        Password::shouldReceive('broker')->andReturn($broker = m::mock(PasswordBroker::class));

        $broker->shouldReceive('deleteToken')
            ->once()
            ->with($user);

        $this->mock(UpdatesUserPasswords::class)
            ->shouldReceive('update')
            ->once()
            ->with($user, [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response = $this->withoutExceptionHandling()->actingAs($user)->putJson('/user/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertStatus(200);
    }

    public function testPasswordsCannotBeUpdatedWithoutCurrentPassword(): void
    {
        $user = $this->createUser();

        try {
            (new UpdateUserPassword)->update($user, [
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $this->fail('The validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertTrue(in_array(
                'The current password field is required.',
                $e->errors()['current_password'],
                true
            ));
        }
    }

    public function testPasswordsCannotBeUpdatedWithoutCurrentPasswordConfirmation(): void
    {
        $user = $this->createUser();

        try {
            (new UpdateUserPassword)->update($user, [
                'current_password' => 'invalid-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

            $this->fail('The validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertTrue(in_array(
                'The provided password does not match your current password.',
                $e->errors()['current_password'],
                true
            ));
        }
    }

    private function createUser(): User
    {
        return User::create(UserFactory::new()->raw());
    }
}
