<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Rules;

use Hypervel\Support\Facades\Validator;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class DateFormatValidationTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        try {
            date_default_timezone_set($this->originalTimezone);
        } finally {
            parent::tearDown();
        }
    }

    #[TestWith(['UTC'])]
    #[TestWith(['Europe/Amsterdam'])]
    public function testItCanValidateRegardlessOfTimezone(string $timezone): void
    {
        date_default_timezone_set($timezone);

        $payload = ['date' => '2025-03-30 02:00:00'];
        $rules = ['date' => 'date_format:Y-m-d H:i:s'];

        $validator = Validator::make($payload, $rules);

        $this->assertTrue($validator->passes());
        $this->assertEmpty($validator->errors()->all());
    }
}
