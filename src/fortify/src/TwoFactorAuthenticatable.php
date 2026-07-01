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
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\Events\RecoveryCodeReplaced;
use UnexpectedValueException;

/**
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
     * Replace the given recovery code with a new one in the user's stored codes.
     */
    public function replaceRecoveryCode(string $code): void
    {
        $replacement = RecoveryCode::generate();

        $codes = array_map(
            static fn (mixed $value): mixed => $value === $code ? $replacement : $value,
            $this->recoveryCodes(),
        );

        $this->forceFill([
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode($codes, JSON_THROW_ON_ERROR)),
        ])->save();

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
