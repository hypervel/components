<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Closure;
use Hypervel\Contracts\Validation\InvokableRule;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidationRule;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\Rules\Email;
use Hypervel\Validation\Rules\Password;
use InvalidArgumentException;

use function Hypervel\Coroutine\parallel;

class ValidationDefaultRuleIsolationTest extends TestCase
{
    public function testConfiguredDefaultsAreClonedBeforeMutation(): void
    {
        $prototype = Password::min(12)->mixedCase();
        Password::defaults($prototype);

        $required = Password::required();
        $default = Password::default();

        $this->assertNotSame($prototype, $required);
        $this->assertNotSame($prototype, $default);
        $this->assertNotSame($required, $default);
        $this->assertSame(['required', 'string', 'min:12'], [...$required]);
        $this->assertSame(['string', 'min:12'], [...$default]);
        $this->assertSame(['string', 'min:12'], [...$prototype]);
    }

    public function testCallableResultsAreClonedBeforeMutation(): void
    {
        $prototype = Password::min(12);
        Password::defaults(static fn (): Password => $prototype);

        $this->assertNotSame($prototype, Password::default());
        $this->assertSame(['string', 'min:12'], [...$prototype]);
    }

    public function testNestedExecutableRulesAreClonedWithThePrototype(): void
    {
        $legacy = new DefaultLegacyRule;
        $invokable = new DefaultInvokableRule;
        $validation = new DefaultValidationRule;

        Password::defaults(Password::min(8)->rules([$legacy, $invokable, $validation]));

        $first = Password::default()->appliedRules()['customRules'];
        $second = Password::default()->appliedRules()['customRules'];

        $this->assertNotSame($legacy, $first[0]);
        $this->assertNotSame($invokable, $first[1]);
        $this->assertNotSame($validation, $first[2]);
        $this->assertNotSame($first[0], $second[0]);
        $this->assertNotSame($first[1], $second[1]);
        $this->assertNotSame($first[2], $second[2]);
    }

    public function testConfiguredDefaultsAreIsolatedBetweenCoroutines(): void
    {
        Password::defaults(Password::min(12));

        $ready = new Channel(1);
        $continue = new Channel(1);

        [$required, $default] = parallel([
            function () use ($ready, $continue): array {
                $rule = Password::required();
                $ready->push(true);
                $continue->pop();

                return [...$rule];
            },
            function () use ($ready, $continue): array {
                $ready->pop();
                $rule = Password::default();
                $continue->push(true);

                return [...$rule];
            },
        ]);

        $this->assertSame(['required', 'string', 'min:12'], $required);
        $this->assertSame(['string', 'min:12'], $default);
    }

    public function testInvalidCallableResultIsRejected(): void
    {
        Password::defaults(static fn (): Email => new Email);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The default callback must return an instance of ' . Password::class . '.');

        Password::default();
    }

    public function testEmailFlushStateClearsTheConfiguredDefault(): void
    {
        Email::defaults((new Email)->preventSpoofing());

        $this->assertTrue(Email::default()->preventSpoofing);

        Email::flushState();

        $this->assertFalse(Email::default()->preventSpoofing);
    }
}

class DefaultLegacyRule implements Rule
{
    public function passes(string $attribute, mixed $value): bool
    {
        return true;
    }

    public function message(): string
    {
        return '';
    }
}

class DefaultInvokableRule implements InvokableRule
{
    public function __invoke(string $attribute, mixed $value, Closure $fail): void
    {
    }
}

class DefaultValidationRule implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
    }
}
