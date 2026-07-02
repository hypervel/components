<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Controllers;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Actions\GenerateVerificationOptions;
use Hypervel\Passkeys\Actions\VerifyPasskey;
use Hypervel\Passkeys\Contracts\PasskeyLoginResponse;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Exceptions\InvalidPasskeyException;
use Hypervel\Passkeys\Http\Requests\PasskeyVerificationRequest;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Routing\Controller;

class PasskeyLoginController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Get passkey login options.
     */
    public function index(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();

        $serialized = WebAuthn::toJson($options);

        $request->session()->put('passkey.login_options', $serialized);

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Verify the passkey and log the user in.
     */
    public function store(
        PasskeyVerificationRequest $request,
        VerifyPasskey $verify,
    ): PasskeyLoginResponse {
        $passkey = $verify(
            $request->credential(),
            $request->verificationOptions('passkey.login_options')
        );

        $user = $passkey->user;

        if (! $user instanceof PasskeyUser || ! Passkeys::allowsLogin($request, $passkey)) {
            throw InvalidPasskeyException::make('Unable to sign in with this account.');
        }

        Passkeys::guard()->login($user, $request->remember());

        $request->session()->regenerate();

        return $this->container->make(PasskeyLoginResponse::class);
    }
}
