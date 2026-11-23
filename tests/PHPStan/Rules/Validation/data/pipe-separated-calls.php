<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Validation\Data;

use Hypervel\Ai\Tools\Request as ToolRequest;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Contracts\Validation\Factory;
use Hypervel\Data\Attributes\Validation\Rule as DataRule;
use Hypervel\Foundation\Validation\ValidatesRequests;
use Hypervel\Http\Request;
use Hypervel\Mcp\Request as McpRequest;
use Hypervel\Prompts\FormBuilder;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Support\Facades\Validator;
use Hypervel\Validation\ConditionalRules;
use Hypervel\Validation\Rule;
use Hypervel\Validation\Rules\AnyOf;
use Hypervel\Validation\Validator as ValidatorInstance;

use function Hypervel\Prompts\text;

class PipeSeparatedCalls
{
    use ValidatesRequests;

    private const string NAME_RULES = 'required|string';

    /**
     * @param list<string> $roles
     */
    public function validator(array $data, Factory $factory, Request $request, ?Request $maybeRequest, ValidatorInstance $validator, Translator $translator, array $roles, int $max, bool $flag): void
    {
        Validator::make($data, ['name' => 'required|string', 'tags' => ['required', 'array']]);
        Validator::validate($data, ['name' => "required|string"]);
        validator($data, ['email' => 'required|email']);
        $factory->make(rules: ['title' => 'required|max:255'], data: $data);
        $request->validate(['title' => 'required|max:255']);
        $request->validateWithBag('post', ['body' => 'required|string']);
        $maybeRequest?->validate(['body' => 'required|string']);
        $this->validate($request, ['body' => 'required|string']);
        $this->validateWithBag('post', $request, ['body' => 'required|string']);
        $this->validateWith(['body' => 'required|string'], $request);
        $validator->setRules(['name' => 'required|string']);
        $validator->sometimes('reason', 'required|max:500', static fn (): bool => true);
        new ValidatorInstance($translator, $data, ['name' => 'required|string']);

        Validator::make($data, [
            'name' => self::NAME_RULES,
            'nickname' => $flag ? 'nullable|string' : 'nullable',
            'role' => 'required|in:' . implode(',', $roles),
            'bio' => "nullable|max:{$max}",
            'code' => 'required|regex:/^(a|b)$/',
            'pattern' => 'regex:/^(a|b)$/',
            'spaced' => 'required | string',
            'trailing' => 'required|',
            'alias' => $flag ? 'nullable|string' : 'required|string',
        ]);
    }

    public function conditions(bool $flag): void
    {
        Rule::when($flag, 'required|string', 'nullable|string');
        Rule::unless(defaultRules: 'nullable|string', rules: ['required', 'string'], condition: $flag);
        new ConditionalRules($flag, 'required|string');
        Rule::anyOf(['required|string', ['name' => 'required|string'], ['required', 'string'], [0 => 'required|string']]);
        new AnyOf(['required|email']);
    }

    public function wrappers(McpRequest $mcpRequest, ToolRequest $toolRequest): void
    {
        $mcpRequest->validate(['location' => 'required|string']);
        $toolRequest->validate(['city' => 'required|string']);
    }

    public function prompts(FormBuilder $form): void
    {
        text('Name', validate: 'required|max:255');
        text('Name', validate: ['name' => 'required|max:255', 'other' => 'required|string']);
        $form->text('Name', validate: ['name' => 'required|min:3']);
        new TextPrompt('Name', validate: 'required|max:5');
    }

    public function data(string $list): void
    {
        new DataRule('required|string', ['max:5|min:1']);
        new DataRule('required|in:' . $list);
        new DataRule('required|regex:/^(a|b)$/');
    }
}
