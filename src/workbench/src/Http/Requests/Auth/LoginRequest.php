<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Requests\Auth;

use Hypervel\Auth\Events\Lockout;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\RateLimiter\Limit;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\RateLimiter;
use Hypervel\Support\Str;
use Hypervel\Validation\ValidationException;

class LoginRequest extends FormRequest
{
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
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::consume($this->throttleLimit());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleLimit());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        $result = RateLimiter::inspect($this->throttleLimit());

        if (! $result->denied()) {
            return;
        }

        if (Event::hasListeners(Lockout::class)) {
            event(new Lockout($this));
        }

        $seconds = $result->resetAfter();

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limit for the request's failed login attempts.
     */
    protected function throttleLimit(): Limit
    {
        return Limit::perMinute(5)->by($this->throttleKey());
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) $this->string('email')) . '|' . $this->ip());
    }
}
