<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\UncastEnvValueRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<UncastEnvValueRule> */
class UncastEnvValueRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(UncastEnvValueRule::class);
    }

    public function testConfigFile(): void
    {
        $error = static fn (string $reader, string $type, int $line): array => [
            $reader . ' can return a string, but its default is ' . $type . '.',
            $line,
            "Cast it in the config file to its default's type, such as (int) env('DB_PORT', 3306). When the variable holds a name or other text, use a string default instead.",
        ];

        // The readers fixture declares an Env subclass with its own get().
        $this->analyse([__DIR__ . '/data/config/uncast-env.php', __DIR__ . '/data/env-readers.php'], [
            $error("env('DB_PORT')", 'an int', 14),
            $error("Hypervel\\Support\\Env::get('APP_DEBUG')", 'a bool', 15),
            $error("env('RATIO')", 'a float', 16),
            $error("env('DEFAULT_TIMEOUT')", 'an int', 17),
            $error("env('RETRIES')", 'an int', 18),
            $error("env('SERVER_WEIGHT')", 'an int', 19),
            $error("env('IDLE')", 'an int', 27),
            $error("env('REPLICA_PORT')", 'an int', 28),
            $error("env('ASSIGNED_PORT')", 'an int', 29),
            $error("Hypervel\\Testbench\\Foundation\\Env::get('TESTBENCH_PORT')", 'an int', 30),
        ]);
    }

    public function testOtherFile(): void
    {
        $this->analyse([__DIR__ . '/data/uncast-env.php'], []);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/data/env-calls.neon'];
    }
}
