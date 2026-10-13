<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use DateTimeImmutable;
use Exception;
use Hypervel\Http\Request;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;

trait RetrievesDeviceCodeFromSession
{
    /**
     * Get the device code from the session.
     *
     * @throws InvalidAuthTokenException
     * @throws Exception
     */
    protected function getDeviceCodeFromSession(Request $request): DeviceCodeEntityInterface
    {
        if ($request->isNotFilled('auth_token')
            || $request->session()->pull('authToken') !== $request->input('auth_token')) {
            $request->session()->forget(['authToken', 'deviceCode', 'authAttributes']);

            throw InvalidAuthTokenException::different();
        }

        $deviceCode = $request->session()->pull('deviceCode')
            ?? throw new Exception('Device code was not present in the session.');

        $deviceCode = unserialize($deviceCode, ['allowed_classes' => [
            \Hypervel\Passport\Bridge\DeviceCode::class,
            \Hypervel\Passport\Bridge\Client::class,
            \Hypervel\Passport\Bridge\Scope::class,
            DateTimeImmutable::class,
        ]]);

        $this->ensureApprovingUser($request, $deviceCode->getUserIdentifier());
        $this->getClientForApproval(
            $deviceCode->getClient()->getIdentifier(),
            'urn:ietf:params:oauth:grant-type:device_code',
            $deviceCode->getScopes()
        );

        return $deviceCode;
    }
}
