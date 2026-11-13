<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine;

use Hypervel\PHPStan\Rules\Coroutine\NoRawCoroutinesRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<NoRawCoroutinesRule> */
class NoRawCoroutinesRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoRawCoroutinesRule::class);
    }

    public function testRule(): void
    {
        $error = static fn (string $spawn, int $line): array => [
            "{$spawn}() starts a coroutine without the framework's exception handling, so an uncaught exception or cancellation stops the worker.",
            $line,
            "Use Hypervel\\Coroutine\\Coroutine::create(), go() or co() instead. Coroutine::fork() and the helpers' copyContext argument copy the parent's context. Keep a direct call, with an ignore, only when its callback catches every exception itself.",
        ];

        $this->analyse([__DIR__ . '/data/raw-coroutines.php'], [
            $error('Swoole\Coroutine::create', 20),
            $error('Swoole\Coroutine\go', 21),
            $error('Hypervel\Engine\Coroutine::create', 22),
            $error('Hypervel\Engine\Coroutine::execute', 23),
            $error('Hypervel\Engine\Coroutine::create', 24),
        ]);
    }

    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-rules.neon'];
    }
}
