<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Controllers;

use Exception;
use Hypervel\Http\Request;
use Hypervel\Passport\Exceptions\InvalidAuthTokenException;
use Hypervel\Support\Facades\Date;
use League\OAuth2\Server\Entities\DeviceCodeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;

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
        $pending = $this->getPendingAuthorization($request);

        try {
            if ($pending['grant_type'] !== 'urn:ietf:params:oauth:grant-type:device_code' || $pending['stage'] !== 'consent') {
                throw InvalidAuthTokenException::different();
            }

            $this->ensureApprovingUser($pending);
            $deviceCode = $this->deviceCodes->getDeviceCodeEntityByDeviceCode($pending['device_code']);

            if ($deviceCode === null || $deviceCode->getUserIdentifier() !== null || $deviceCode->getExpiryDateTime() <= Date::now()) {
                throw OAuthServerException::invalidRequest('user_code');
            }

            $this->getClientForApproval(
                $deviceCode->getClient()->getIdentifier(),
                $pending['grant_type'],
                $deviceCode->getScopes(),
            );
            $deviceCode->setUserIdentifier($pending['user_id']);

            return $deviceCode;
        } finally {
            $this->pending->pull($request, $request->input('auth_token'));
        }
    }
}
