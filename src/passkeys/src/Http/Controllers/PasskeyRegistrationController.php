<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Controllers;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Actions\DeletePasskey;
use Hypervel\Passkeys\Actions\GenerateRegistrationOptions;
use Hypervel\Passkeys\Actions\StorePasskey;
use Hypervel\Passkeys\Contracts\PasskeyDeletedResponse;
use Hypervel\Passkeys\Contracts\PasskeyRegistrationResponse;
use Hypervel\Passkeys\Http\Requests\PasskeyRegistrationRequest;
use Hypervel\Passkeys\Passkey;
use Hypervel\Passkeys\Passkeys;
use Hypervel\Passkeys\Support\WebAuthn;
use Hypervel\Routing\Controller;

class PasskeyRegistrationController extends Controller
{
    public function __construct(
        private readonly Container $container,
    ) {
    }

    /**
     * Get passkey registration options for the authenticated user.
     */
    public function index(Request $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $user = Passkeys::guard()->user()
            ?? throw new AuthenticationException;

        $options = $generate($user);

        $serialized = WebAuthn::toJson($options);

        $request->session()->put('passkey.registration_options', $serialized);

        return response()->json([
            'options' => WebAuthn::toBrowserArray($options),
        ]);
    }

    /**
     * Store a new passkey for the authenticated user.
     */
    public function store(
        PasskeyRegistrationRequest $request,
        StorePasskey $storePasskey,
    ): PasskeyRegistrationResponse {
        $user = Passkeys::guard()->user()
            ?? throw new AuthenticationException;

        $passkey = $storePasskey(
            $user,
            (string) $request->string('name'),
            $request->credential(),
            $request->registrationOptions()
        );

        return $this->container->make(PasskeyRegistrationResponse::class)->withPasskey($passkey);
    }

    /**
     * Delete a passkey for the authenticated user.
     */
    public function destroy(
        Passkey $passkey,
        DeletePasskey $deletePasskey
    ): PasskeyDeletedResponse {
        $user = Passkeys::guard()->user()
            ?? throw new AuthenticationException;

        $deletePasskey($user, $passkey);

        return $this->container->make(PasskeyDeletedResponse::class);
    }
}
