<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Actions;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Events\RecoveryCodesGenerated;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\RecoveryCode;
use Hypervel\Support\Collection;

class GenerateNewRecoveryCodes
{
    use DispatchesEvents;

    public function __construct(
        protected readonly Dispatcher $events,
    ) {
    }

    /**
     * Generate new recovery codes for the user.
     */
    public function __invoke(Authenticatable&Model $user): void
    {
        $user->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, static fn (): string => RecoveryCode::generate())->all(), JSON_THROW_ON_ERROR)),
        ])->save();

        $this->dispatchIfListening(
            $this->events,
            RecoveryCodesGenerated::class,
            static fn (): RecoveryCodesGenerated => new RecoveryCodesGenerated($user),
        );
    }
}
