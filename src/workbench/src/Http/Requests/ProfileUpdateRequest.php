<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Requests;

use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Foundation\Http\FormRequest;
use Hypervel\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>|string|ValidationRule>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                // Check the authenticated model's own table and key instead of the base User model's.
                Rule::unique($this->user()::class)->ignore($this->user()),
            ],
        ];
    }
}
