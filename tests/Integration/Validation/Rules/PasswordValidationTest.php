<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Rules;

use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rules\Password;
use PHPUnit\Framework\Attributes\TestWith;

class PasswordValidationTest extends TestCase
{
    #[TestWith(['0'])]
    #[TestWith(['.'])]
    #[TestWith(['*'])]
    #[TestWith(['__asterisk__'])]
    public function testItCanValidateAttributeAsArray(string $attribute): void
    {
        $validator = Validator::make([
            'passwords' => [
                $attribute => 'secret',
            ],
        ], [
            'passwords.*' => ['required', Password::default()->min(6)],
        ]);

        $this->assertTrue($validator->passes());
    }

    #[TestWith(['0'])]
    #[TestWith(['.'])]
    #[TestWith(['*'])]
    #[TestWith(['__asterisk__'])]
    public function testItCanValidateAttributeAsArrayWhenValidationShouldFails(string $attribute): void
    {
        $validator = Validator::make([
            'passwords' => [
                $attribute => 'secret',
            ],
        ], [
            'passwords.*' => ['required', Password::default()->min(8)],
        ]);

        $this->assertFalse($validator->passes());

        $this->assertSame([
            0 => sprintf('The passwords.%s field must be at least 8 characters.', str_replace('_', ' ', $attribute)),
        ], $validator->messages()->all());
    }
}
