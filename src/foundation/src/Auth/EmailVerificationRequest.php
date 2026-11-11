<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Validation\Validator;

class EmailVerificationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        if (! hash_equals((string) $this->user()->getKey(), (string) $this->route('id'))) {
            return false;
        }

        if (! hash_equals(sha1($this->user()->getEmailForVerification()), (string) $this->route('hash'))) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Fulfill the email verification request.
     */
    public function fulfill(): void
    {
        if (! $this->user()->hasVerifiedEmail()) {
            $this->user()->markEmailAsVerified();

            /** @var Dispatcher $events */
            $events = app('events');

            if ($events->hasListeners(Verified::class)) {
                $events->dispatch(new Verified($this->user()));
            }
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator(Validator $validator): Validator
    {
        return $validator;
    }
}
