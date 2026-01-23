<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Psr\Container\ContainerInterface;
use Pusher\ApiErrorException;
use Pusher\Pusher;

class PusherBroadcaster extends Broadcaster
{
    use UsePusherChannelConventions;

    /**
     * Create a new broadcaster instance.
     */
    public function __construct(
        protected ContainerInterface $container,
        protected Pusher $pusher
    ) {
    }

    /**
     * Resolve the authenticated user payload for an incoming connection request.
     *
     * See: https://pusher.com/docs/channels/library_auth_reference/auth-signatures/#user-authentication
     * See: https://pusher.com/docs/channels/server_api/authenticating-users/#response
     */
    public function resolveAuthenticatedUser(RequestInterface $request): ?array
    {
        if (! $user = parent::resolveAuthenticatedUser($request)) {
            return null;
        }

        if (method_exists($this->pusher, 'authenticateUser')) { // @phpstan-ignore function.alreadyNarrowedType (Pusher 6.x compatibility)
            return json_decode(
                $this->pusher->authenticateUser($request->input('socket_id'), $user),
                true,
            );
        }

        $settings = $this->pusher->getSettings();
        $encodedUser = json_encode($user);
        $decodedString = "{$request->input('socket_id')}::user::{$encodedUser}";

        $auth = $settings['auth_key'] . ':' . hash_hmac(
            'sha256',
            $decodedString,
            $settings['secret']
        );

        return [
            'auth' => $auth,
            'user_data' => $encodedUser,
        ];
    }

    /**
     * Authenticate the incoming request for a given channel.
     *
     * @throws AccessDeniedHttpException
     */
    public function auth(RequestInterface $request): mixed
    {
        $channelName = $request->input('channel_name');
        $normalizeChannelName = $this->normalizeChannelName($channelName);

        if (empty($channelName)
            || ($this->isGuardedChannel($channelName) && ! $this->retrieveUser($normalizeChannelName))
        ) {
            throw new AccessDeniedHttpException();
        }

        return parent::verifyUserCanAccessChannel(
            $request,
            $normalizeChannelName
        );
    }

    /**
     * Return the valid authentication response.
     */
    public function validAuthenticationResponse(RequestInterface $request, mixed $result): mixed
    {
        $channelName = $request->input('channel_name');
        $socketId = $request->input('socket_id');

        if (str_starts_with($channelName, 'private')) {
            return $this->decodePusherResponse(
                $this->pusher->authorizeChannel($channelName, $socketId),
            );
        }

        $user = $this->retrieveUser(
            $this->normalizeChannelName($channelName)
        );

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        return $this->decodePusherResponse(
            $this->pusher->authorizePresenceChannel($channelName, $socketId, (string) $broadcastIdentifier, $result)
        );
    }

    /**
     * Decode the given Pusher response.
     */
    protected function decodePusherResponse(mixed $response): array
    {
        return json_decode($response, true);
    }

    /**
     * Broadcast the given event.
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        $socket = Arr::pull($payload, 'socket');

        $parameters = $socket !== null ? ['socket_id' => $socket] : [];

        $channels = Collection::make($this->formatChannels($channels));

        try {
            $channels->chunk(100)->each(function ($channels) use ($event, $payload, $parameters) {
                $this->pusher->trigger($channels->toArray(), $event, $payload, $parameters);
            });
        } catch (ApiErrorException $e) {
            throw new BroadcastException(
                sprintf('Pusher error: %s.', $e->getMessage())
            );
        }
    }

    /**
     * Get the Pusher SDK instance.
     */
    public function getPusher(): Pusher
    {
        return $this->pusher;
    }

    /**
     * Set the Pusher SDK instance.
     */
    public function setPusher(Pusher $pusher): void
    {
        $this->pusher = $pusher;
    }
}
