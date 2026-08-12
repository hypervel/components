<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Fortify\Contracts\VerifyEmailViewResponse;
use Hypervel\Fortify\Http\Responses\RedirectAsIntended;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): mixed
    {
        /** @var MustVerifyEmail $user */
        $user = $request->user();

        return $user->hasVerifiedEmail()
            ? app(RedirectAsIntended::class, ['name' => 'email-verification'])
            : app(VerifyEmailViewResponse::class);
    }
}
