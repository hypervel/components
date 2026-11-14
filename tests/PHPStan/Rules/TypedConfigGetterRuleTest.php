<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Closure;
use Hypervel\PHPStan\Rules\TypedConfigGetterRule;
use Hypervel\PHPStan\Support\RuntimeInputs;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

use function sprintf;

/** @extends RuleTestCase<TypedConfigGetterRule> */
class TypedConfigGetterRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(TypedConfigGetterRule::class);
    }

    public function testApplication(): void
    {
        $error = static fn (string $key, string $description, string $getter, int $line): array => [
            sprintf('Config key %s always holds %s, so read it with %s().', $key, $description, $getter),
            $line,
            sprintf("Use config()->%1\$s(), Config::%1\$s() or the repository's %1\$s(), which return the value's type and throw if the configuration changes it.", $getter),
        ];

        // The analyzer tests boot a Testbench package application.
        $packageApplication = self::setPackageApplication(false);

        try {
            $this->analyse([__DIR__ . '/data/typed-config-getters.php'], [
                $error("'typed.name'", 'a string', 'string', 15),
                $error("'typed.port'", 'an integer', 'integer', 16),
                $error("'typed.ratio'", 'a float', 'float', 17),
                $error("'typed.debug'", 'a boolean', 'boolean', 18),
                $error("'typed.hosts'", 'an array', 'array', 19),
                $error("'typed.label'|'typed.name'", 'a string', 'string', 20),
            ]);
        } finally {
            self::setPackageApplication($packageApplication);
        }
    }

    public function testPackageApplication(): void
    {
        $this->analyse([__DIR__ . '/data/typed-config-getters.php'], []);
    }

    /**
     * Set whether the booted application is a package's, returning the previous value.
     */
    private static function setPackageApplication(bool $packageApplication): bool
    {
        return Closure::bind(static function () use ($packageApplication): bool {
            $previous = RuntimeInputs::$packageApplication;
            RuntimeInputs::$packageApplication = $packageApplication;

            return $previous;
        }, null, RuntimeInputs::class)();
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/phpstan-rules.neon'];
    }
}
