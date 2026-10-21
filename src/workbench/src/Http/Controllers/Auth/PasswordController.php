<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Hash;
use Hypervel\Validation\Rules\Password;
use Hypervel\Workbench\Http\Controllers\Controller;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        return back()->with('status', 'password-updated');
    }
}
