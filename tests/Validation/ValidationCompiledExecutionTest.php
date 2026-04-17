<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation\ValidationCompiledExecutionTest;

use Closure;
use Hypervel\Contracts\Validation\ImplicitRule;
use Hypervel\Contracts\Validation\Rule as RuleContract;
use Hypervel\Http\UploadedFile;
use Hypervel\Tests\TestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\PresenceVerifierInterface;
use Hypervel\Validation\Validator;

class ValidationCompiledExecutionTest extends TestCase
{
    public function testBasicPassFail()
    {
        $v = $this->makeValidator(['name' => 'John'], ['name' => 'required|string|max:255']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['name' => ''], ['name' => 'required|string']);
        $this->assertFalse($v->passes());
    }

    public function testValidatedOutput()
    {
        $v = $this->makeValidator(
            ['name' => 'John', 'age' => 30, 'extra' => 'ignored'],
            ['name' => 'required|string', 'age' => 'required|integer'],
        );

        $this->assertSame(['name' => 'John', 'age' => 30], $v->validate());
    }

    public function testFailedOutput()
    {
        $v = $this->makeValidator(['name' => 123], ['name' => 'required|string']);
        $v->passes();

        $failed = $v->failed();
        $this->assertArrayHasKey('name', $failed);
        $this->assertArrayHasKey('String', $failed['name']);
    }

    public function testErrorMessagesWithReplacements()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines([
            'validation.max.string' => ':attribute must not be greater than :max characters.',
        ], 'en');

        $v = new Validator($translator, ['name' => 'toolong'], ['name' => 'string|max:3']);
        $v->passes();

        $this->assertStringContainsString('3', $v->errors()->first('name'));
    }

    public function testBailStopsOnFirstFailure()
    {
        $v = $this->makeValidator(['name' => 123], ['name' => 'bail|string|max:255']);
        $v->passes();

        $this->assertCount(1, $v->errors()->get('name'));
    }

    public function testStopOnFirstFailure()
    {
        $v = $this->makeValidator(
            ['a' => 123, 'b' => 456],
            ['a' => 'string', 'b' => 'string'],
        );
        $v->stopOnFirstFailure();
        $v->passes();

        $this->assertTrue($v->errors()->has('a'));
        $this->assertFalse($v->errors()->has('b'));
    }

    public function testSometimesRespectsPresence()
    {
        $v = $this->makeValidator([], ['name' => 'sometimes|required|string']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['name' => ''], ['name' => 'sometimes|required|string']);
        $this->assertFalse($v->passes());
    }

    public function testNullableWithRequired()
    {
        $v = $this->makeValidator(['name' => null], ['name' => 'nullable|required']);
        $this->assertFalse($v->passes());

        $v = $this->makeValidator(['name' => null], ['name' => 'nullable|string']);
        $this->assertTrue($v->passes());
    }

    public function testRequiredOnEmptyString()
    {
        $v = $this->makeValidator(['name' => ''], ['name' => 'required|string']);
        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('name'));
    }

    public function testCustomMessages()
    {
        $v = $this->makeValidator(
            ['name' => ''],
            ['name' => 'required'],
            ['name.required' => 'The name field is mandatory.'],
        );
        $v->passes();

        $this->assertSame('The name field is mandatory.', $v->errors()->first('name'));
    }

    public function testCustomAttributeNames()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines(['validation.required' => ':attribute is required.'], 'en');

        $v = new Validator($translator, ['name' => ''], ['name' => 'required']);
        $v->setAttributeNames(['name' => 'Full Name']);
        $v->passes();

        $this->assertSame('Full Name is required.', $v->errors()->first('name'));
    }

    public function testClosureRule()
    {
        $v = $this->makeValidator(
            ['code' => 'invalid'],
            ['code' => [function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== 'valid') {
                    $fail('The code is invalid.');
                }
            }]],
        );

        $this->assertFalse($v->passes());
        $this->assertSame('The code is invalid.', $v->errors()->first('code'));
    }

    public function testRuleContractObject()
    {
        $rule = new class implements RuleContract {
            public function passes(string $attribute, mixed $value): bool
            {
                return $value === 'valid';
            }

            public function message(): array|string
            {
                return 'The :attribute is invalid.';
            }
        };

        $v = $this->makeValidator(['code' => 'invalid'], ['code' => [$rule]]);
        $this->assertFalse($v->passes());
    }

    public function testImplicitRuleRunsOnAbsentAttribute()
    {
        $rule = new class implements RuleContract, ImplicitRule {
            public function passes(string $attribute, mixed $value): bool
            {
                return $value !== null;
            }

            public function message(): array|string
            {
                return 'The :attribute is required.';
            }
        };

        $v = $this->makeValidator([], ['name' => [$rule]]);
        $this->assertFalse($v->passes());
    }

    public function testCustomExtension()
    {
        $v = $this->makeValidator(['code' => 'abc'], ['code' => 'custom_check']);
        $v->addExtension('custom_check', function ($attribute, $value) {
            return $value === 'valid';
        });

        $this->assertFalse($v->passes());
    }

    public function testDependentRulesResolveCorrectly()
    {
        $v = $this->makeValidator(
            ['password' => 'secret', 'password_confirmation' => 'secret'],
            ['password' => 'confirmed'],
        );
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(
            ['password' => 'secret', 'password_confirmation' => 'different'],
            ['password' => 'confirmed'],
        );
        $this->assertFalse($v->passes());
    }

    public function testExcludeRulesProduceCorrectValidatedOutput()
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'title' => 'My Post', 'publish_date' => '2025-01-01'],
            [
                'type' => 'required|string',
                'title' => 'required|string',
                'publish_date' => 'exclude_if:type,draft|required|date',
            ],
        );

        $validated = $v->validate();
        $this->assertArrayNotHasKey('publish_date', $validated);
        $this->assertArrayHasKey('title', $validated);
    }

    public function testDependentRulesWithWildcardParameters()
    {
        $v = $this->makeValidator(
            ['items' => [
                ['start' => '2025-01-01', 'end' => '2025-12-31'],
                ['start' => '2025-06-01', 'end' => '2025-03-01'],
            ]],
            [
                'items.*.start' => 'required|date',
                'items.*.end' => 'required|date|after:items.*.start',
            ],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('items.1.end'));
        $this->assertFalse($v->errors()->has('items.0.end'));
    }

    public function testBooleanStrictDelegated()
    {
        $v = $this->makeValidator(['flag' => true], ['flag' => 'boolean:strict']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['flag' => 1], ['flag' => 'boolean:strict']);
        $this->assertFalse($v->passes());
    }

    public function testNumericStrictDelegated()
    {
        $v = $this->makeValidator(['age' => 30], ['age' => 'numeric:strict']);
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(['age' => '30'], ['age' => 'numeric:strict']);
        $this->assertFalse($v->passes());
    }

    public function testInWithSiblingArrayUsesArrayDiffBranch()
    {
        $v = $this->makeValidator(
            ['tags' => ['php', 'js']],
            ['tags' => 'array', 'tags.*' => 'in:php,js,go'],
        );
        $this->assertTrue($v->passes());

        $v = $this->makeValidator(
            ['tags' => ['php', 'python']],
            ['tags' => 'array', 'tags.*' => 'in:php,js,go'],
        );
        $this->assertFalse($v->passes());
    }

    public function testWildcardValidationWithCorrectIndices()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $translator->addLines(['validation.string' => ':attribute must be a string.'], 'en');

        $v = new Validator(
            $translator,
            ['items' => [['name' => 'valid'], ['name' => 123]]],
            ['items.*.name' => 'required|string'],
        );

        $this->assertFalse($v->passes());
        $this->assertTrue($v->errors()->has('items.1.name'));
        $this->assertFalse($v->errors()->has('items.0.name'));
    }

    public function testSubclassWithOverriddenValidateStringIsNotBypassed()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $v = new AlwaysFailStringValidator($translator, ['name' => 'hello'], ['name' => 'string']);

        $this->assertFalse($v->passes());
    }

    public function testPreOptimizationGuardSkipsWithCustomExtensions()
    {
        $v = $this->makeValidator(
            ['type' => 'section', 'details' => 'test'],
            ['type' => 'required|string', 'details' => 'exclude_unless:type,chapter|required|string'],
        );
        $v->addExtension('my_ext', function () {
            return true;
        });

        $this->assertTrue($v->passes());
        $this->assertArrayNotHasKey('details', $v->validated());
    }

    public function testCustomPresenceVerifierDisablesBatching()
    {
        $customVerifier = new class implements PresenceVerifierInterface {
            public function getCount(string $collection, string $column, string $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
            {
                return $value === 'exists@example.com' ? 1 : 0;
            }

            public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
            {
                return 0;
            }
        };

        $v = $this->makeValidator(
            ['items' => [['email' => 'exists@example.com']]],
            ['items.*.email' => 'required|exists:users,email'],
        );
        $v->setPresenceVerifier($customVerifier);

        $this->assertTrue($v->passes());
    }

    public function testInvalidUploadedFileProducesUploadedError()
    {
        $file = new UploadedFile(
            path: '',
            originalName: 'test.jpg',
            mimeType: 'image/jpeg',
            error: UPLOAD_ERR_INI_SIZE,
            test: true,
        );

        $v = $this->makeValidator(['file' => $file], ['file' => 'required|image']);
        $v->passes();

        $this->assertTrue($v->errors()->has('file'));
    }

    public function testExcludeAttributesResetAcrossValidatorReuse()
    {
        $v = $this->makeValidator(
            ['type' => 'draft', 'details' => 'some details'],
            ['type' => 'required|string', 'details' => 'exclude_if:type,draft|required|string'],
        );

        $v->passes();
        $this->assertArrayNotHasKey('details', $v->validated());

        // Reuse the same validator with different data
        $v->setData(['type' => 'published', 'details' => 'some details']);
        $v->passes();

        // details should now be INCLUDED (type is no longer draft)
        $this->assertArrayHasKey('details', $v->validated());
    }

    public function testPresenceVerifierRestoredAfterException()
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $v = new Validator($translator, ['name' => 'test'], ['name' => 'required|string']);

        $originalVerifier = new class implements PresenceVerifierInterface {
            public function getCount(string $collection, string $column, string $value, int|string|null $excludeId = null, ?string $idColumn = null, array $extra = []): int
            {
                return 0;
            }

            public function getMultiCount(string $collection, string $column, array $values, array $extra = []): int
            {
                return 0;
            }
        };

        $v->setPresenceVerifier($originalVerifier);

        // First passes() should work normally
        $this->assertTrue($v->passes());

        // The presence verifier should still be the original after passes()
        $this->assertSame($originalVerifier, $v->getPresenceVerifier());
    }

    private function makeValidator(array $data, array $rules, array $messages = []): Validator
    {
        return new Validator(
            new Translator(new ArrayLoader, 'en'),
            $data,
            $rules,
            $messages,
        );
    }
}

/**
 * Test validator subclass that overrides validateString to always fail.
 *
 * Used to verify that subclass validate*() overrides are not bypassed
 * by inlining — subclasses must use compileAllDelegated().
 */
class AlwaysFailStringValidator extends Validator
{
    public function validateString(string $attribute, mixed $value): bool
    {
        return false;
    }
}
