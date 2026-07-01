<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Http\Responses;

use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Hypervel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Hypervel\Passkeys\Passkey;
use Symfony\Component\HttpFoundation\Response;

class PasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
{
    public function __construct(
        private ?Passkey $passkey = null,
    ) {
    }

    /**
     * Set the passkey that was registered.
     */
    public function withPasskey(Passkey $passkey): static
    {
        $response = clone $this;
        $response->passkey = $passkey;

        return $response;
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if (! $request->wantsJson()) {
            return back()->with('status', 'passkey-registered');
        }

        $data = ['status' => 'passkey-registered'];

        if ($this->passkey instanceof Passkey) {
            $data['id'] = (string) $this->passkey->id;
            $data['name'] = $this->passkey->name;
        }

        return new JsonResponse($data, 200);
    }
}
