<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation\Data;

use Hypervel\Data\Data;
use Hypervel\Data\Support\Validation\ValidationContext;
use Hypervel\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        if ($this->isMethod('put')) {
            return ['title' => 'sometimes|string'];
        }

        $nested = static fn (): array => ['ignored' => 'required|string'];

        return [
            'title' => 'required|string|max:255',
            'tags' => ['array', 'string|max:5'],
            'code' => 'regex:/^(a|b)$/',
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Use letters|numbers.'];
    }
}

class PostData extends Data
{
    public function __construct(
        public string $title,
    ) {
    }

    public static function rules(ValidationContext $context): array
    {
        return [
            'title' => 'required|string',
            'tags' => ['array', 'required|min:1'],
            'code' => 'regex:/^(a|b)$/',
        ];
    }
}

class NotARequest
{
    public function rules(): array
    {
        return ['title' => 'required|string'];
    }
}
