<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Rules;

use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Rules\Email;
use PHPUnit\Framework\Attributes\TestWith;

/**
 * @internal
 * @coversNothing
 */
class EmailValidationTest extends TestCase
{
    #[TestWith(['0'])]
    #[TestWith(['.'])]
    #[TestWith(['*'])]
    #[TestWith(['__asterisk__'])]
    public function testItCanValidateAttributeAsArray(string $attribute): void
    {
        $validator = Validator::make([
            'emails' => [
                $attribute => 'info@hypervel.org',
            ],
        ], [
            'emails.*' => ['required', Email::default()->rfcCompliant()],
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
            'emails' => [
                $attribute => 'info[at]hypervel.org',
            ],
        ], [
            'emails.*' => ['required', Email::default()->rfcCompliant()],
        ]);

        $this->assertFalse($validator->passes());

        $this->assertSame([
            0 => __('validation.email', ['attribute' => sprintf('emails.%s', str_replace('_', ' ', $attribute))]),
        ], $validator->messages()->all());
    }
}
