<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\NoImplicitQueryBuilderCallRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function str_replace;

/** @extends RuleTestCase<NoImplicitQueryBuilderCallRule> */
class NoImplicitQueryBuilderCallRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(NoImplicitQueryBuilderCallRule::class);
    }

    /** @param list<array{string, int}> $errors */
    #[DataProvider('dataCalls')]
    public function testCalls(string $file, array $errors): void
    {
        $file = __DIR__ . '/data/' . $file;
        $fixedFile = str_replace('.php', '-fixed.php', $file);

        $this->analyse([$file], $errors);
        $this->fix($file, $fixedFile);
        $this->analyse([$fixedFile], []);
    }

    /** @return iterable<string, array{string, list<array{string, int}>}> */
    public static function dataCalls(): iterable
    {
        yield 'builder calls and legacy scopes' => [
            'no-implicit-query-builder-call.php',
            [
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 34],
                ["Call to static method NoImplicitQueryBuilderCall\\User::active() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->active() instead.", 35],
                ["Call to method NoImplicitQueryBuilderCall\\User::recent() is forwarded to the query builder.\n    💡 Use newQuery()->recent() instead.", 36],
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 88],
                ["Call to static method NoImplicitQueryBuilderCall\\User::find() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->find() instead.", 89],
                ["Call to static method NoImplicitQueryBuilderCall\\User::create() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->create() instead.", 90],
                ["Call to static method NoImplicitQueryBuilderCall\\User::first() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->first() instead.", 91],
                ["Call to static method NoImplicitQueryBuilderCall\\User::whereIn() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->whereIn() instead.", 92],
                ["Call to static method NoImplicitQueryBuilderCall\\User::active() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->active() instead.", 93],
                ["Call to static method NoImplicitQueryBuilderCall\\User::recent() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->recent() instead.", 94],
                ["Call to static method NoImplicitQueryBuilderCall\\User::whereId() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->whereId() instead.", 95],
                ["Call to static method NoImplicitQueryBuilderCall\\Post::published() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\Post::query()->published() instead.", 96],
                ["Call to method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 97],
                ["Call to method NoImplicitQueryBuilderCall\\User::find() is forwarded to the query builder.\n    💡 Use newQuery()->find() instead.", 98],
                ["Call to method NoImplicitQueryBuilderCall\\User::active() is forwarded to the query builder.\n    💡 Use newQuery()->active() instead.", 99],
                ["Call to method NoImplicitQueryBuilderCall\\User::recent() is forwarded to the query builder.\n    💡 Use newQuery()->recent() instead.", 100],
                ["Call to method NoImplicitQueryBuilderCall\\User::whereId() is forwarded to the query builder.\n    💡 Use newQuery()->whereId() instead.", 101],
                ["Call to method NoImplicitQueryBuilderCall\\Post::published() is forwarded to the query builder.\n    💡 Use newQuery()->published() instead.", 102],
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 130],
                ["Call to static method NoImplicitQueryBuilderCall\\User::active() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->active() instead.", 131],
                ["Call to static method NoImplicitQueryBuilderCall\\User::find() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->find() instead.", 133],
                ["Call to method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 148],
                // Hypervel: a where{Column} call is forwarded as a dynamic where whatever the column, which ModelPropertyColumnRule checks.
                ["Call to static method NoImplicitQueryBuilderCall\\User::whereNonexistentColumn() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->whereNonexistentColumn() instead.", 153],
                ["Call to method NoImplicitQueryBuilderCall\\User::whereNonexistentColumn() is forwarded to the query builder.\n    💡 Use newQuery()->whereNonexistentColumn() instead.", 154],
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 160],
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 161],
                ["Call to method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 164],
                ["Call to method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 165],
                ["Call to static method NoImplicitQueryBuilderCall\\User::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCall\\User::query()->where() instead.", 167],
            ],
        ];

        yield 'attribute scopes' => [
            'no-implicit-query-builder-call-scopes.php',
            [
                ["Call to static method NoImplicitQueryBuilderCallScopes\\User::active() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCallScopes\\User::query()->active() instead.", 56],
                ["Call to method NoImplicitQueryBuilderCallScopes\\User::active() is forwarded to the query builder.\n    💡 Use newQuery()->active() instead.", 57],
                ["Call to static method NoImplicitQueryBuilderCallScopes\\ChildUser::active() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCallScopes\\ChildUser::query()->active() instead.", 58],
                ["Call to method NoImplicitQueryBuilderCallScopes\\ChildUser::active() is forwarded to the query builder.\n    💡 Use newQuery()->active() instead.", 59],
            ],
        ];

        yield 'builder attribute' => [
            'no-implicit-query-builder-call-custom-builder.php',
            [
                ["Call to static method NoImplicitQueryBuilderCallCustomBuilder\\User::named() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCallCustomBuilder\\User::query()->named() instead.", 25],
                ["Call to method NoImplicitQueryBuilderCallCustomBuilder\\User::named() is forwarded to the query builder.\n    💡 Use newQuery()->named() instead.", 26],
            ],
        ];

        // Hypervel: a model that forwards the call differently from Model would get another query, while a
        // custom newQuery() runs in both forms.
        yield 'customized forwarding' => [
            'no-implicit-query-builder-call-forwarding.php',
            [
                ["Call to method NoImplicitQueryBuilderCallForwarding\\QueriedUser::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 41],
                ["Call to static method NoImplicitQueryBuilderCallForwarding\\ScopedUser::where() is forwarded to the query builder.\n    💡 Use NoImplicitQueryBuilderCallForwarding\\ScopedUser::query()->where() instead.", 42],
                ["Call to method NoImplicitQueryBuilderCallForwarding\\ScopedUser::where() is forwarded to the query builder.\n    💡 Use newQuery()->where() instead.", 43],
            ],
        ];
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../Type/data/config-check-model-properties.neon'];
    }
}
