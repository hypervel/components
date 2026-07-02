<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Controllers;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Actions\GenerateVerificationOptions;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Contracts\PasskeyConfirmationResponse;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Routing\Controller;
use Hypervel\Session\Store as SessionStore;
use RuntimeException;

class PasskeyConfirmationController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Get passkey confirmation options for the authenticated user.
     */
    public function index(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $user = Passkeys::guard()->user()
            ?? throw new AuthenticationException;

        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        $options = $generate($user);

        $serialized = WebAuthn::toJson($options);

        $request->session()->put('passkey.confirmation_options', $serialized);

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Confirm the user's password via passkey verification.
     */
    public function store(
        PasskeyVerificationRequest $request,
        VerifyPasskey $verify,
    ): PasskeyConfirmationResponse {
        $user = Passkeys::guard()->user()
            ?? throw new AuthenticationException;

        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        $verify(
            $request->credential(),
            $request->verificationOptions('passkey.confirmation_options'),
            $user
        );

        /** @var SessionStore $session */
        $session = $request->session();

        $session->passwordConfirmed();

        return $this->container->make(PasskeyConfirmationResponse::class);
    }
}
