<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify;

use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Hash;
use Hypervel\Testbench\Attributes\WithMigration;
use Hypervel\Tests\Fortify\Fixtures\UpdateUserPassword;
use Hypervel\Validation\ValidationException;
use Workbench\App\Models\User;
use Workbench\Database\Factories\UserFactory;

// REMOVED: Laravel Fortify's deprecated Rules\Password compatibility class is intentionally omitted; Hypervel uses the framework password validation rule directly.
#[WithMigration]
class PasswordRuleTest extends TestCase
{
    use RefreshDatabase;

    public function testModernPasswordValidationRuleAllowsValidPasswordUpdates(): void
    {
        $user = User::forceCreate(UserFactory::new()->raw([
            'email' => 'taylor@laravel.com',
        ]));

        $this->actingAs($user);

        (new UpdateUserPassword)->update($user, [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
    }

    public function testModernPasswordValidationRuleIsUsedByPasswordUpdater(): void
    {
        $user = User::forceCreate(UserFactory::new()->raw([
            'email' => 'taylor@laravel.com',
        ]));

        try {
            $this->actingAs($user);

            (new UpdateUserPassword)->update($user, [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ]);

            $this->fail('The validation exception was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(
                'The password field must be at least 8 characters.',
                $e->errors()['password'][0],
            );
        }
    }
}
