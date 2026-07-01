<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Passkeys\Concerns\DispatchesEvents;
use Hypervel\Passkeys\Contracts\PasskeyUser;
use Hypervel\Passkeys\Events\PasskeyDeleted;
use Hypervel\Passkeys\Passkey;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DeletePasskey
{
    use DispatchesEvents;

    public function __construct(
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Delete the given passkey.
     */
    public function __invoke(Authenticatable $user, Passkey $passkey): void
    {
        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        if (! $this->passkeyBelongsToUser($passkey, $user)) {
            throw new HttpException(403);
        }

        $passkey->delete();

        $this->dispatchIfListening(
            $this->events,
            PasskeyDeleted::class,
            static fn (): PasskeyDeleted => new PasskeyDeleted($user, $passkey),
        );
    }

    /**
     * Determine if the passkey belongs to the given user.
     */
    private function passkeyBelongsToUser(Passkey $passkey, PasskeyUser $user): bool
    {
        $identifier = $user->getKey();

        if (! is_scalar($identifier)) {
            return false;
        }

        return $passkey->user_type === $user->getMorphClass()
            && (string) $passkey->user_id === (string) $identifier;
    }
}
