<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Fixtures;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Fortify\Contracts\UpdatesUserPasswords;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Validator;
use Hypervel\Validation\Rules\Password;
use Hypervel\Validation\ValidationException;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * Validate and update the user's password.
     *
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function update(Authenticatable $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
