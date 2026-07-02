<?php

declare(strict_types=1);

namespace Hypervel\Fortify;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\Events\RecoveryCodeReplaced;
use UnexpectedValueException;

/**
 * @phpstan-require-extends Model
 * @phpstan-require-implements Authenticatable
 * @phpstan-require-implements TwoFactorAuthenticationUser
 */
trait TwoFactorAuthenticatable
{
    /**
     * Determine if two-factor authentication has been enabled.
     */
    public function hasEnabledTwoFactorAuthentication(): bool
    {
        if (Fortify::confirmsTwoFactorAuthentication()) {
            return ! is_null($this->two_factor_secret)
                   && ! is_null($this->two_factor_confirmed_at);
        }

        return ! is_null($this->two_factor_secret);
    }

    /**
     * Get the user's two factor authentication recovery codes.
     *
     * @return array<int, string>
     */
    public function recoveryCodes(): array
    {
        $codes = json_decode(
            Fortify::currentEncrypter()->decrypt($this->two_factor_recovery_codes),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($codes)) {
            throw new UnexpectedValueException('Two-factor recovery codes must decode to an array.');
        }

        return $codes;
    }

    /**
     * Consume the given recovery code if it is still valid.
     */
    public function consumeRecoveryCode(string $code): bool
    {
        $consumed = $this->getConnection()->transaction(function () use ($code): bool {
            /** @var null|(Model&TwoFactorAuthenticationUser) $user */
            $user = $this->newQuery()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->first();

            if (! $user instanceof TwoFactorAuthenticationUser) {
                return false;
            }

            $matched = false;
            $replacement = RecoveryCode::generate();

            $codes = array_map(
                static function (mixed $value) use ($code, $replacement, &$matched): mixed {
                    if (! $matched && is_string($value) && hash_equals($value, $code)) {
                        $matched = true;

                        return $replacement;
                    }

                    return $value;
                },
                $user->recoveryCodes(),
            );

            if (! $matched) {
                return false;
            }

            $encryptedCodes = Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR));

            $user->forceFill([
                'two_factor_recovery_codes' => $encryptedCodes,
            ])->save();

            $this->forceFill([
                'two_factor_recovery_codes' => $encryptedCodes,
            ])->syncOriginalAttribute('two_factor_recovery_codes');

            return true;
        });

        if ($consumed) {
            $this->dispatchRecoveryCodeReplacedEvent($code);
        }

        return $consumed;
    }

    /**
     * Replace the given recovery code with a new one in the user's stored codes.
     */
    public function replaceRecoveryCode(string $code): void
    {
        $this->consumeRecoveryCode($code);
    }

    /**
     * Dispatch the recovery code replaced event if listeners are registered.
     */
    protected function dispatchRecoveryCodeReplacedEvent(string $code): void
    {
        $events = Container::getInstance()->make(Dispatcher::class);

        if ($events->hasListeners(RecoveryCodeReplaced::class)) {
            $events->dispatch(new RecoveryCodeReplaced($this, $code));
        }
    }

    /**
     * Get the QR code SVG of the user's two factor authentication QR code URL.
     */
    public function twoFactorQrCodeSvg(): string
    {
        $svg = (new Writer(
            new ImageRenderer(
                new RendererStyle(192, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(45, 55, 72))),
                new SvgImageBackEnd
            )
        ))->writeString($this->twoFactorQrCodeUrl());

        return trim(substr($svg, strpos($svg, "\n") + 1));
    }

    /**
     * Get the two factor authentication QR code URL.
     */
    public function twoFactorQrCodeUrl(): string
    {
        return Container::getInstance()->make(TwoFactorAuthenticationProvider::class)->qrCodeUrl(
            Container::getInstance()->make(Config::class)->string('app.name'),
            $this->{Fortify::username()},
            Fortify::currentEncrypter()->decrypt($this->two_factor_secret)
        );
    }
}
