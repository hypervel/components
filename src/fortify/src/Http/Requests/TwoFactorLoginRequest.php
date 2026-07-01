<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Requests;

use Hypervel\Auth\EloquentUserProvider;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Http\Exceptions\HttpResponseException;
use RuntimeException;

class TwoFactorLoginRequest extends FormRequest
{
    protected ?Authenticatable $challengedUser = null;

    protected ?bool $remember = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'code' => 'nullable|string',
            'recovery_code' => 'nullable|string',
        ];
    }

    /**
     * Determine if the request has a valid two factor code.
     */
    public function hasValidCode(): bool
    {
        $code = $this->input('code');

        if (! is_string($code) || $code === '') {
            return false;
        }

        $secret = $this->challengedUser()->getAttribute('two_factor_secret');

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $result = $this->container()->make(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($secret),
            $code,
        );

        if ($result) {
            $this->session()->forget(['login.id', 'login.guard']);
        }

        return $result;
    }

    /**
     * Get the valid recovery code if one exists on the request.
     */
    public function validRecoveryCode(): ?string
    {
        $recoveryCode = $this->input('recovery_code');

        if (! is_string($recoveryCode) || $recoveryCode === '') {
            return null;
        }

        /** @var Authenticatable&Model&TwoFactorAuthenticationUser $user */
        $user = $this->challengedUser();

        $code = collect($user->recoveryCodes())->first(
            fn (string $code): bool => hash_equals($code, $recoveryCode),
        );

        if (is_string($code)) {
            $this->session()->forget(['login.id', 'login.guard']);
        }

        return is_string($code) ? $code : null;
    }

    /**
     * Determine if there is a challenged user in the current session.
     */
    public function hasChallengedUser(): bool
    {
        if ($this->challengedUser instanceof Authenticatable) {
            return true;
        }

        if (! $this->challengeGuardMatchesCurrentGuard()) {
            return false;
        }

        $model = $this->providerModel();

        return $this->session()->has('login.id')
            && $model::query()->whereKey($this->session()->get('login.id'))->exists();
    }

    /**
     * Get the user that is attempting the two factor challenge.
     */
    public function challengedUser(): Authenticatable&Model
    {
        if ($this->challengedUser instanceof Authenticatable && $this->challengedUser instanceof Model) {
            return $this->challengedUser;
        }

        if (! $this->challengeGuardMatchesCurrentGuard()) {
            throw new HttpResponseException(
                $this->container()->make(FailedTwoFactorLoginResponse::class)->toResponse($this)
            );
        }

        $model = $this->providerModel();

        if (! $this->session()->has('login.id')
            || ! $user = $model::query()->find($this->session()->get('login.id'))) {
            throw new HttpResponseException(
                $this->container()->make(FailedTwoFactorLoginResponse::class)->toResponse($this)
            );
        }

        if (! $user instanceof Authenticatable) {
            throw new RuntimeException('Fortify two-factor login requires an authenticatable Eloquent model.');
        }

        return $this->challengedUser = $user;
    }

    /**
     * Determine if the user wanted to be remembered after login.
     */
    public function remember(): bool
    {
        if ($this->remember === null) {
            $this->remember = (bool) $this->session()->pull('login.remember', false);
        }

        return $this->remember;
    }

    /**
     * Get the selected guard provider's model class.
     *
     * @return class-string<Model>
     */
    private function providerModel(): string
    {
        $guard = Fortify::guard();

        if (! method_exists($guard, 'getProvider')) {
            throw new RuntimeException('Fortify two-factor login requires an Eloquent authentication guard provider.');
        }

        $provider = $guard->getProvider(); /* @phpstan-ignore method.notFound (getProvider() is on GuardHelpers, not the guard contract) */

        if (! $provider instanceof EloquentUserProvider) {
            throw new RuntimeException('Fortify two-factor login requires an Eloquent authentication guard provider.');
        }

        return $provider->getModel();
    }

    /**
     * Determine if the challenged guard is still the current request guard.
     */
    private function challengeGuardMatchesCurrentGuard(): bool
    {
        $guard = $this->session()->get('login.guard');

        return is_string($guard) && $guard === Fortify::guardName();
    }

    /**
     * Get the container instance.
     */
    private function container(): ContainerContract
    {
        return Container::getInstance();
    }
}
