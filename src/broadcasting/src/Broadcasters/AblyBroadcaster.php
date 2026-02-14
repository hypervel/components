<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Broadcasters;

use Ably\AblyRest;
use Ably\Exceptions\AblyException;
use Ably\Models\Message as AblyMessage;
use Hypervel\HttpServer\Contracts\RequestInterface;
use Hypervel\Broadcasting\BroadcastException;
use Hypervel\Contracts\Container\Container;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\Support\Str;

class AblyBroadcaster extends Broadcaster
{
    /**
     * Create a new broadcaster instance.
     */
    public function __construct(
        protected Container $container,
        protected AblyRest $ably,
    ) {
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
            $signature = $this->generateAblySignature($channelName, $socketId);

            return ['auth' => $this->getPublicToken() . ':' . $signature];
        }

        $user = $this->retrieveUser(
            $this->normalizeChannelName($channelName)
        );

        $broadcastIdentifier = method_exists($user, 'getAuthIdentifierForBroadcasting')
            ? $user->getAuthIdentifierForBroadcasting()
            : $user->getAuthIdentifier();

        $signature = $this->generateAblySignature(
            $channelName,
            $socketId,
            $userData = array_filter([
                'user_id' => (string) $broadcastIdentifier,
                'user_info' => $result,
            ])
        );

        return [
            'auth' => $this->getPublicToken() . ':' . $signature,
            'channel_data' => json_encode($userData),
        ];
    }

    /**
     * Generate the signature needed for Ably authentication headers.
     */
    public function generateAblySignature(string $channelName, string $socketId, ?array $userData = null): string
    {
        return hash_hmac(
            'sha256',
            sprintf('%s:%s%s', $socketId, $channelName, $userData ? ':' . json_encode($userData) : ''),
            $this->getPrivateToken(),
        );
    }

    /**
     * Broadcast the given event.
     *
     * @throws BroadcastException
     */
    public function broadcast(array $channels, string $event, array $payload = []): void
    {
        try {
            foreach ($this->formatChannels($channels) as $channel) {
                $this->ably->channels->get($channel)->publish(  // @phpstan-ignore-line
                    $this->buildAblyMessage($event, $payload)   // @phpstan-ignore-line
                );                                              // @phpstan-ignore-line
            }
        } catch (AblyException $e) {
            throw new BroadcastException(
                sprintf('Ably error: %s', $e->getMessage())
            );
        }
    }

    /**
     * Build an Ably message object for broadcasting.
     */
    protected function buildAblyMessage(string $event, array $payload = []): AblyMessage
    {
        return tap(new AblyMessage(), function ($message) use ($event, $payload) {
            $message->name = $event;
            $message->data = $payload;
            $message->connectionKey = data_get($payload, 'socket');
        });
    }

    /**
     * Return true if the channel is protected by authentication.
     */
    public function isGuardedChannel(string $channel): bool
    {
        return Str::startsWith($channel, ['private-', 'presence-']);
    }

    /**
     * Remove prefix from channel name.
     */
    public function normalizeChannelName(string $channel): string
    {
        if ($this->isGuardedChannel($channel)) {
            return str_starts_with($channel, 'private-')
                        ? Str::replaceFirst('private-', '', $channel)
                        : Str::replaceFirst('presence-', '', $channel);
        }

        return $channel;
    }

    /**
     * Format the channel array into an array of strings.
     */
    protected function formatChannels(array $channels): array
    {
        return array_map(function ($channel) {
            $channel = (string) $channel;

            if (Str::startsWith($channel, ['private-', 'presence-'])) {
                return str_starts_with($channel, 'private-')
                    ? Str::replaceFirst('private-', 'private:', $channel)
                    : Str::replaceFirst('presence-', 'presence:', $channel);
            }

            return 'public:' . $channel;
        }, $channels);
    }

    /**
     * Get the public token value from the Ably key.
     */
    protected function getPublicToken(): string
    {
        return Str::before($this->ably->options->key, ':');
    }

    /**
     * Get the private token value from the Ably key.
     */
    protected function getPrivateToken(): string
    {
        return Str::after($this->ably->options->key, ':');
    }

    /**
     * Get the underlying Ably SDK instance.
     */
    public function getAbly(): AblyRest
    {
        return $this->ably;
    }

    /**
     * Set the underlying Ably SDK instance.
     */
    public function setAbly(AblyRest $ably): void
    {
        $this->ably = $ably;
    }
}
