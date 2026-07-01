<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Contracts\Container\Container;
use Hypervel\Fortify\Contracts\EmailVerificationNotificationSentResponse;
use Hypervel\Fortify\Http\Responses\RedirectAsIntended;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;

class EmailVerificationNotificationController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Send a new email verification notification.
     */
    public function store(Request $request): mixed
    {
        /** @var MustVerifyEmail $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return $request->wantsJson()
                ? new JsonResponse('', 204)
                : $this->container->make(RedirectAsIntended::class, ['name' => 'email-verification']);
        }

        $user->sendEmailVerificationNotification();

        return $this->container->make(EmailVerificationNotificationSentResponse::class);
    }
}
