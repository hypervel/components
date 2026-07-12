<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Requests;

use Hypervel\Fortify\Fortify;
use Hypervel\Foundation\Http\FormRequest;

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
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            Fortify::username() => 'required|string',
            'password' => 'required|string',
            'remember' => 'sometimes',
        ];
    }
}
