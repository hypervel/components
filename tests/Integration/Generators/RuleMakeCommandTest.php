<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Generators;

use PHPUnit\Framework\Attributes\DataProvider;

class RuleMakeCommandTest extends TestCase
{
    protected array $files = [
        'app/Rules/Foo.php',
        'app/Rules/PotentiallyTranslatedString.php',
    ];

    public function testItCanGenerateRuleFile(): void
    {
        $this->artisan('make:rule', ['name' => 'Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Rules;',
            'use Hypervel\Contracts\Validation\ValidationRule;',
            'class Foo implements ValidationRule',
        ], 'app/Rules/Foo.php');
    }

    public function testItCanGenerateInvokableRuleFile(): void
    {
        $this->artisan('make:rule', ['name' => 'Foo'])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Rules;',
            'use Hypervel\Contracts\Validation\ValidationRule;',
            'class Foo implements ValidationRule',
            'public function validate(string $attribute, mixed $value, Closure $fail): void',
        ], 'app/Rules/Foo.php');
    }

    public function testItCanGenerateImplicitRuleFile(): void
    {
        $this->artisan('make:rule', ['name' => 'Foo', '--implicit' => true])
            ->assertExitCode(0);

        $this->assertFileContains([
            'namespace App\Rules;',
            'use Hypervel\Contracts\Validation\ValidationRule;',
            'class Foo implements ValidationRule',
            'public bool $implicit = true;',
            'public function validate(string $attribute, mixed $value, Closure $fail): void',
        ], 'app/Rules/Foo.php');
    }

    #[DataProvider('ruleStubOptions')]
    public function testItCanGenerateRuleFileNamedPotentiallyTranslatedString(array $options): void
    {
        $this->artisan('make:rule', ['name' => 'PotentiallyTranslatedString', ...$options])
            ->assertExitCode(0);

        $this->assertPhpFileCompiles('app/Rules/PotentiallyTranslatedString.php');
    }

    /**
     * Provide the options that select each rule stub.
     */
    public static function ruleStubOptions(): array
    {
        return [
            'rule' => [[]],
            'implicit rule' => [['--implicit' => true]],
        ];
    }
}
