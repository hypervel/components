<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation\Data;

use Hypervel\Data\Attributes\Validation\Rule as DataRule;
use Hypervel\Foundation\Validation\ValidatesRequests;
use Hypervel\Http\Request;
use Hypervel\Prompts\FormBuilder;
use Hypervel\Support\Facades\Validator;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Validator as ValidatorInstance;

use function Hypervel\Prompts\text;

class CorrectValidationRules
{
    /**
     * @param list<array<string, mixed>> $arguments
     * @param list<string> $rules
     */
    public function arrays(array $data, Request $request, ValidatorInstance $validator, array $arguments, array $rules, string $prefix, string $pattern): void
    {
        Validator::make($data, [
            'name' => ['required', 'string'],
            'code' => ['regex:/^(a|b)$/'],
            'items.*.code' => ['not_regex:/^(a|b)$/'],
            // The validator does not split array elements, so this fails at runtime as an unknown rule instead.
            'broken' => ['required', 'string|max:5'],
            'callback' => static fn (string $attribute, mixed $value, callable $fail): null => null,
            'each' => Rule::forEach(static fn (): string => 'required|string'),
            'plain' => 'required',
        ]);

        $variable = ['name' => 'required|string'];
        Validator::make($data, $variable);
        Validator::make(...$arguments);
        $request->validate(...)(['name' => 'required|string']);
        $validator->hasRule('name', 'required|string');

        Rule::anyOf([...$rules, ['required', 'string']]);
        new DataRule('regex:/^(a|b)$/', ['required', 'string']);
        new DataRule($prefix . '|b)$/');
        new DataRule('regex:/^(' . $pattern . '|b)$/');
    }

    public function prompts(FormBuilder $form): void
    {
        text('Name', validate: ['required', 'max:255']);
        text('Name', validate: static fn (string $value): ?string => null);
        $form->text('Name', validate: ['name' => ['required', 'max:255']]);
    }

    public function unrelated(Unrelated $unrelated, OverridingController $controller, Request $request): void
    {
        $unrelated->validate(['name' => 'required|string']);
        $unrelated->when(true, 'required|string');
        $controller->validate($request, ['name' => 'required|string']);
    }
}

class Unrelated
{
    public function validate(array $rules): void
    {
    }

    public function when(bool $condition, string $rules): void
    {
    }
}

class OverridingController
{
    use ValidatesRequests;

    public function validate(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        return [];
    }
}
