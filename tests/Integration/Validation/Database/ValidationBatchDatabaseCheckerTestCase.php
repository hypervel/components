<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\Database;

use Closure;
use DateTimeImmutable;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\QueryException;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\BatchDatabaseChecker;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\PrecomputedPresenceVerifier;
use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\Validator;
use RuntimeException;
use Stringable;

abstract class ValidationBatchDatabaseCheckerTestCase extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('batch_test_users', function (Blueprint $table) {
            $table->id();
            $table->integer('external_id')->unique();
            $table->string('email')->unique();
            $table->string('status')->default('active');
            $table->string('lookup_value')->nullable();
            $table->decimal('score', 8, 2)->nullable();
            $table->date('joined_on')->nullable();
            $table->timestamp('scheduled_at')->nullable();
        });

        $this->app->make('db')->table('batch_test_users')->insert([
            ['external_id' => 0, 'email' => 'user1@example.com', 'status' => 'active', 'lookup_value' => 'Case', 'score' => 1.25, 'joined_on' => '2025-01-01', 'scheduled_at' => '2025-01-01 00:00:00'],
            ['external_id' => 1, 'email' => 'user2@example.com', 'status' => 'active', 'lookup_value' => 'café', 'score' => 2.50, 'joined_on' => '2025-01-02', 'scheduled_at' => null],
            ['external_id' => 2, 'email' => 'user3@example.com', 'status' => 'inactive', 'lookup_value' => 'trimmed', 'score' => 3.75, 'joined_on' => '2025-01-03', 'scheduled_at' => null],
            ['external_id' => 100, 'email' => 'numeric@example.com', 'status' => 'active', 'lookup_value' => '1', 'score' => null, 'joined_on' => null, 'scheduled_at' => null],
        ]);
    }

    public function testBuildVerifierReturnsNullWhenNoLookups(): void
    {
        $presenceVerifier = $this->app->make('validation.presence');
        $this->assertInstanceOf(DatabasePresenceVerifier::class, $presenceVerifier);

        $this->assertNull(BatchDatabaseChecker::buildVerifier([], $presenceVerifier));
    }

    public function testDistinctCountSemanticsForArrayValuedExists(): void
    {
        $presenceVerifier = $this->app->make('validation.presence');
        $this->assertInstanceOf(DatabasePresenceVerifier::class, $presenceVerifier);
        $verifier = new PrecomputedPresenceVerifier($presenceVerifier);
        $lookupKey = PrecomputedPresenceVerifier::lookupKey(null, 'batch_test_users', 'email');
        $bindingKey = PrecomputedPresenceVerifier::bindingKey('user1@example.com');
        $this->assertNotNull($lookupKey);
        $this->assertNotNull($bindingKey);
        $verifier->addLookup(
            $lookupKey,
            exactHits: [$bindingKey => true],
            knownPresent: [],
            provenAbsent: [],
            stageOneSingleChunk: true,
        );

        $count = $verifier->getMultiCount(
            'batch_test_users',
            'email',
            ['user1@example.com', 'user1@example.com'],
        );

        $this->assertSame(1, $count);
    }

    // ─── End-to-end validator tests ──────────────────────────────────────

    public function testBatchingActivatesEndToEndForStringFormExists(): void
    {
        $data = ['items' => []];
        for ($i = 0; $i < 10; ++$i) {
            $data['items'][] = ['email' => 'user' . (($i % 3) + 1) . '@example.com'];
        }

        $validator = $this->makeValidator($data, [
            'items.*.email' => 'required|exists:batch_test_users,email',
        ]);

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);

        $existsQueries = array_filter($queryLog, function ($entry) {
            return str_contains($entry['query'], 'batch_test_users');
        });

        $this->assertCount(1, $existsQueries);
    }

    public function testBatchingProducesCorrectPassFailResults(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'nonexistent@example.com'],
                ['email' => 'user3@example.com'],
            ]],
            ['items.*.email' => 'required|exists:batch_test_users,email'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('items.1.email'));
        $this->assertFalse($validator->errors()->has('items.0.email'));
        $this->assertFalse($validator->errors()->has('items.2.email'));
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testAllNewUniqueValuesUseOneGroupedQueryWithoutFallbacks(): void
    {
        $items = [];

        for ($index = 0; $index < 10; ++$index) {
            $items[] = ['email' => "new-{$index}@example.com"];
        }

        $validator = $this->makeValidator(
            ['items' => $items],
            ['items.*.email' => 'required|unique:batch_test_users,email'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testBatchedPresenceMatchesOrdinaryVerifierDatabaseEquality(): void
    {
        $probes = [
            ['lookup_value', 'case'],
            ['lookup_value', 'cafe'],
            ['lookup_value', 'trimmed '],
            ['score', 1.25],
            ['score', '2.50'],
            ['external_id', '1'],
            ['external_id', 1.0],
        ];

        foreach ($probes as [$column, $value]) {
            foreach (['exists', 'unique'] as $rule) {
                $ordinary = $this->makeValidator(
                    ['value' => $value],
                    ['value' => "{$rule}:batch_test_users,{$column}"],
                );
                $batched = $this->makeValidator(
                    ['items' => [['value' => $value]]],
                    ['items.*.value' => "{$rule}:batch_test_users,{$column}"],
                );

                $this->assertSame(
                    $ordinary->passes(),
                    $batched->passes(),
                    "Batched {$rule} diverged for {$column} and " . var_export($value, true),
                );
            }
        }
    }

    public function testDateTimeCandidatesUseTheOrdinaryVerifierBindingConversion(): void
    {
        $value = new ValidationPresenceDomainDate('2025-01-01 00:00:00');

        foreach (['exists', 'unique'] as $rule) {
            $ordinary = $this->makeValidator(
                ['value' => $value],
                ['value' => "{$rule}:batch_test_users,scheduled_at"],
            );
            $batched = $this->makeValidator(
                ['items' => [['value' => $value]]],
                ['items.*.value' => "{$rule}:batch_test_users,scheduled_at"],
            );

            $this->assertSame($ordinary->passes(), $batched->passes());
        }
    }

    #[RequiresDatabase(['mysql', 'mariadb', 'sqlite'])]
    public function testIntegerCandidateAgainstTextUsesOnePrecomputedQueryWhereSupported(): void
    {
        foreach (['exists', 'unique'] as $rule) {
            $ordinary = $this->makeValidator(
                ['value' => 1],
                ['value' => "{$rule}:batch_test_users,lookup_value"],
            );
            $batched = $this->makeValidator(
                ['items' => [['value' => 1]]],
                ['items.*.value' => "{$rule}:batch_test_users,lookup_value"],
            );

            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                $result = $batched->passes();
                $queryLog = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
            }

            $this->assertSame($ordinary->passes(), $result);
            $this->assertCount(1, array_filter(
                $queryLog,
                static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
            ));
        }
    }

    #[RequiresDatabase(['mysql', 'mariadb', 'sqlite'])]
    public function testMixedStringAndIntegerTextCandidatesRetainBothBindings(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['value' => '1'], ['value' => 1]]],
            ['items.*.value' => 'exists:batch_test_users,lookup_value'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);

        $presenceQueries = array_values(array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));

        $this->assertCount(1, $presenceQueries);
        $this->assertSame(['1', 1], $presenceQueries[0]['bindings']);
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testMixedStringAndIntegerCandidatesPreserveOrdinaryMySqlTextComparison(): void
    {
        $this->app->make('db')->table('batch_test_users')
            ->where('external_id', 100)
            ->update(['lookup_value' => '01']);

        $ordinaryString = $this->makeValidator(
            ['value' => '1'],
            ['value' => 'exists:batch_test_users,lookup_value'],
        );
        $ordinaryInteger = $this->makeValidator(
            ['value' => 1],
            ['value' => 'exists:batch_test_users,lookup_value'],
        );

        $this->assertFalse($ordinaryString->passes());
        $this->assertTrue($ordinaryInteger->passes());

        foreach ([['1', 1], [1, '1']] as $values) {
            $validator = $this->makeValidator(
                ['items' => array_map(
                    static fn (int|string $value): array => ['value' => $value],
                    $values,
                )],
                ['items.*.value' => 'exists:batch_test_users,lookup_value'],
            );

            $this->assertFalse($validator->passes());

            foreach ($values as $index => $value) {
                $this->assertSame(
                    is_string($value),
                    $validator->errors()->has("items.{$index}.value"),
                );
            }
        }
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testCaseInsensitiveUniqueUsesDatabaseEquality(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['email' => 'USER1@EXAMPLE.COM']]],
            ['items.*.email' => 'required|unique:batch_test_users,email'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('items.0.email'));
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    #[RequiresDatabase(['mysql', 'mariadb'])]
    public function testArrayPresenceUsesDatabaseDistinctEquivalenceClasses(): void
    {
        $this->app->make('db')->table('batch_test_users')->insert([
            'external_id' => 3,
            'email' => 'user4@example.com',
            'status' => 'active',
            'lookup_value' => 'case',
        ]);

        $presenceVerifier = $this->app->make('validation.presence');
        $this->assertInstanceOf(DatabasePresenceVerifier::class, $presenceVerifier);
        $this->assertCount(1, $presenceVerifier->getExistingValues(
            'batch_test_users',
            'lookup_value',
            ['Case', 'case'],
            null,
        ));

        $ordinary = $this->makeValidator(
            ['values' => ['Case', 'case']],
            ['values' => 'array|exists:batch_test_users,lookup_value'],
        );
        $batched = $this->makeValidator(
            ['items' => [['values' => ['Case', 'case']]]],
            ['items.*.values' => 'array|exists:batch_test_users,lookup_value'],
        );

        $this->assertFalse($ordinary->passes());
        $this->assertFalse($batched->passes());
    }

    #[RequiresDatabase('pgsql')]
    public function testInvalidTypedValuesFailBeforeAnyPresenceQuery(): void
    {
        $validator = $this->makeValidator(
            ['items' => [[
                'external_id' => 'not-an-integer',
                'date' => 'not-a-date',
                'formatted_date' => '01/02/2025',
            ]]],
            [
                'items.*.external_id' => 'integer|exists:batch_test_users,external_id',
                'items.*.date' => 'date|exists:batch_test_users,joined_on',
                'items.*.formatted_date' => 'date_format:Y-m-d|exists:batch_test_users,joined_on',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('items.0.external_id'));
        $this->assertTrue($validator->errors()->has('items.0.date'));
        $this->assertTrue($validator->errors()->has('items.0.formatted_date'));
        $this->assertCount(0, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    #[RequiresDatabase('pgsql')]
    public function testPostgresPreservesIntegerToTextBindingErrorsOnOrdinaryAndBatchedPaths(): void
    {
        $ordinary = $this->makeValidator(
            ['value' => 1],
            ['value' => 'exists:batch_test_users,lookup_value'],
        );

        $this->assertThrows($ordinary->passes(...), QueryException::class);

        foreach ([['1', 1], [1, '1']] as $values) {
            $batched = $this->makeValidator(
                ['items' => array_map(
                    static fn (int|string $value): array => ['value' => $value],
                    $values,
                )],
                ['items.*.value' => 'exists:batch_test_users,lookup_value'],
            );

            $this->assertThrows($batched->passes(...), QueryException::class);
        }
    }

    public function testOriginalPresenceVerifierIsRestoredAfterExceptionDuringBatchedValidation(): void
    {
        $validator = $this->makeValidator(
            [
                'items' => [['email' => 'user1@example.com']],
                'boom' => 'trigger',
            ],
            [
                'items.*.email' => 'required|exists:batch_test_users,email',
                'boom' => [function (string $attribute, mixed $value, Closure $fail): void {
                    throw new RuntimeException('boom');
                }],
            ],
        );

        $originalVerifier = $validator->getPresenceVerifier();

        try {
            $validator->passes();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame($originalVerifier, $validator->getPresenceVerifier());
    }

    public function testDifferentWildcardQueryShapesOnSameTableColumnBatchIndependently(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                [
                    'active_email' => 'user1@example.com',
                    'any_email' => 'user3@example.com',
                ],
            ]],
            [
                'items.*.active_email' => 'required|exists:batch_test_users,email,status,active',
                'items.*.any_email' => 'required|exists:batch_test_users,email',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testNonWildcardRuleCanConsumeFactsFromAnIdenticalWildcardShape(): void
    {
        $validator = $this->makeValidator(
            [
                'email' => 'user1@example.com',
                'items' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                ],
            ],
            [
                'email' => 'required|exists:batch_test_users,email',
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testArrayFormExistsRuleWithExtraConditionsKeepsFactsIsolatedByQueryShape(): void
    {
        $validator = $this->makeValidator(
            [
                'email' => 'user3@example.com',
                'items' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                ],
            ],
            [
                'email' => [['exists', 'batch_test_users', 'email', 'status', 'active']],
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('email'));
        $this->assertFalse($validator->errors()->has('items.0.email'));
        $this->assertFalse($validator->errors()->has('items.1.email'));
    }

    public function testFieldReferenceIgnoreIsNotBatched(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com', 'id' => 1],
                ['email' => 'user2@example.com', 'id' => 2],
            ]],
            [
                'items.*.email' => 'required|unique:batch_test_users,email,[items.*.id]',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testModelClassRuleResolvesCorrectly(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
            ]],
            ['items.*.email' => 'required|exists:' . BatchTestUser::class . ',email'],
        );

        $this->assertTrue($validator->passes());
    }

    public function testModelTableParsingIsMemoizedWithinEachValidationExecution(): void
    {
        CountingBatchTestUser::$constructions = 0;

        try {
            $validator = $this->makeValidator(
                ['items' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                    ['email' => 'user3@example.com'],
                ]],
                ['items.*.email' => 'required|exists:' . CountingBatchTestUser::class . ',email'],
            );

            $this->assertTrue($validator->passes());
            $this->assertSame(1, CountingBatchTestUser::$constructions);
            $this->assertTrue($validator->passes());
            $this->assertSame(2, CountingBatchTestUser::$constructions);
        } finally {
            CountingBatchTestUser::$constructions = 0;
        }
    }

    public function testObjectFormExistsRulesBatchCorrectly(): void
    {
        $rule = new Exists('batch_test_users', 'email');

        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
            ]],
            ['items.*.email' => ['required', $rule]],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testObjectFormUniqueRuleWithIgnoreBatchesCorrectly(): void
    {
        $rule = (new Unique('batch_test_users', 'email'))
            ->ignore(1, 'id');

        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
                ['email' => 'new@example.com'],
            ]],
            ['items.*.email' => ['required', $rule]],
        );

        $this->assertFalse($validator->passes());
        $this->assertFalse($validator->errors()->has('items.0.email'));
        $this->assertTrue($validator->errors()->has('items.1.email'));
        $this->assertFalse($validator->errors()->has('items.2.email'));
    }

    public function testObjectFormUniqueRulePreservesZeroIgnoreWhenBatched(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'new@example.com'],
            ]],
            ['items.*.email' => [
                'required',
                (new Unique('batch_test_users', 'email'))->ignore(0, 'external_id'),
            ]],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testCallbackBearingPresenceRulesRemainDelegated(): void
    {
        $existsCallbackCalls = 0;
        $uniqueCallbackCalls = 0;
        $exists = (new Exists('batch_test_users', 'email'))->where(
            function ($query) use (&$existsCallbackCalls): void {
                ++$existsCallbackCalls;
                $query->where('status', 'active');
            },
        );
        $unique = (new Unique('batch_test_users', 'email'))->where(
            function ($query) use (&$uniqueCallbackCalls): void {
                ++$uniqueCallbackCalls;
                $query->where('status', 'active');
            },
        );
        $validator = $this->makeValidator(
            ['items' => [
                ['existing' => 'user1@example.com', 'unique' => 'user1@example.com'],
                ['existing' => 'user3@example.com', 'unique' => 'user3@example.com'],
            ]],
            [
                'items.*.existing' => ['required', $exists],
                'items.*.unique' => ['required', $unique],
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertFalse($validator->errors()->has('items.0.existing'));
        $this->assertTrue($validator->errors()->has('items.1.existing'));
        $this->assertTrue($validator->errors()->has('items.0.unique'));
        $this->assertFalse($validator->errors()->has('items.1.unique'));
        $this->assertSame(2, $existsCallbackCalls);
        $this->assertSame(2, $uniqueCallbackCalls);
    }

    public function testStringFormUniqueRuleUnescapesIgnoredValueBeforeBatching(): void
    {
        $email = 'slash\id@example.com';

        $this->app->make('db')->table('batch_test_users')->insert([
            'external_id' => 3,
            'email' => $email,
            'status' => 'active',
        ]);

        $rule = (string) (new Unique('batch_test_users', 'email'))
            ->ignore($email, 'email');

        $validator = $this->makeValidator(
            ['items' => [
                ['email' => $email],
                ['email' => 'new@example.com'],
            ]],
            ['items.*.email' => ['required', $rule]],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);

        $uniqueQueries = array_filter($queryLog, function ($entry) {
            return str_contains($entry['query'], 'batch_test_users');
        });

        $this->assertCount(1, $uniqueQueries);
    }

    public function testArrayFormExistsRuleCanConsumeFactsFromIdenticalWildcardShape(): void
    {
        $validator = $this->makeValidator(
            [
                'email' => 'user1@example.com',
                'items' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                ],
            ],
            [
                'email' => [['exists', 'batch_test_users', 'email']],
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testNonBatchableStringWildcardRuleDoesNotCorruptResults(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com', 'id' => 1],
                ['email' => 'user2@example.com', 'id' => 2],
            ]],
            [
                'items.*.email' => [
                    'required',
                    'exists:batch_test_users,email',
                    'unique:batch_test_users,email,[items.*.id]',
                ],
            ],
        );

        $this->assertTrue($validator->passes());
    }

    public function testObjectFormExistsWithInferredColumnKeepsFactsIsolatedByQueryShape(): void
    {
        $rule = (new Exists('batch_test_users'))
            ->where('status', 'active');

        $validator = $this->makeValidator(
            [
                'email' => 'user3@example.com',
                'items' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                ],
            ],
            [
                'email' => ['required', $rule],
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('email'));
        $this->assertFalse($validator->errors()->has('items.0.email'));
        $this->assertFalse($validator->errors()->has('items.1.email'));
    }

    public function testArrayValuedExistsRulesBatchOneDimensionalValues(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['emails' => ['user1@example.com', 'user2@example.com', 'user1@example.com']],
                ['emails' => ['user3@example.com', 'missing@example.com']],
            ]],
            ['items.*.emails' => ['required', 'array', 'exists:batch_test_users,email']],
        );

        $this->assertFalse($validator->passes());
        $this->assertFalse($validator->errors()->has('items.0.emails'));
        $this->assertTrue($validator->errors()->has('items.1.emails'));
    }

    public function testSafePrefixesSubmitOnlyValuesThatCanReachPresenceValidation(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['external_id' => 1],
                ['external_id' => 'invalid'],
                ['external_id' => 2],
            ]],
            ['items.*.external_id' => 'required|integer|exists:batch_test_users,external_id'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertFalse($validator->errors()->has('items.0.external_id'));
        $this->assertTrue($validator->errors()->has('items.1.external_id'));
        $this->assertFalse($validator->errors()->has('items.2.external_id'));
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testResolvedFirstExclusionKeepsPresenceChecksBatchable(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['type' => 'chapter', 'email' => 'user1@example.com'],
                ['type' => 'chapter', 'email' => 'user2@example.com'],
                ['type' => 'chapter', 'email' => 'user3@example.com'],
            ]],
            ['items.*.email' => 'exclude_if:items.*.type,none|required|exists:batch_test_users,email'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testMutationGateKeepsFirstExclusionPresenceChecksDelegated(): void
    {
        $validator = $this->makeValidator(
            [
                'prepare' => true,
                'items' => [
                    ['type' => 'chapter', 'email' => 'user1@example.com'],
                    ['type' => 'chapter', 'email' => 'user2@example.com'],
                    ['type' => 'chapter', 'email' => 'user3@example.com'],
                ],
            ],
            [
                'prepare' => 'prepare_items',
                'items.*.email' => 'exclude_if:items.*.type,none|required|exists:batch_test_users,email',
            ],
        );
        $validator->addExtension('prepare_items', static fn (): bool => true);

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(3, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testUnsafeFailingPrefixDoesNotIssueAPresenceQuery(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['external_id' => 'invalid']]],
            ['items.*.external_id' => 'multiple_of:5|exists:batch_test_users,external_id'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('items.0.external_id'));
        $this->assertCount(0, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testUncertainPassingPrefixFallsBackWithoutDisablingSafeSiblingBatching(): void
    {
        $validator = $this->makeValidator(
            ['items' => [[
                'safe_id' => 1,
                'uncertain_id' => 2,
            ]]],
            [
                'items.*.safe_id' => 'required|integer|exists:batch_test_users,external_id',
                'items.*.uncertain_id' => ['regex:/^\d+$/', 'exists:batch_test_users,external_id'],
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testBooleanCandidateFallsBackWithoutDisablingSafeSiblingBatching(): void
    {
        $validator = $this->makeValidator(
            ['items' => [[
                'safe_value' => 'user1@example.com',
                'boolean_value' => true,
            ]]],
            [
                'items.*.safe_value' => 'required|exists:batch_test_users,email',
                'items.*.boolean_value' => 'required|exists:batch_test_users,email',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertFalse($validator->errors()->has('items.0.safe_value'));
        $this->assertTrue($validator->errors()->has('items.0.boolean_value'));
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testStopOnFirstFailureSkipsSpeculativePresenceBatching(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['value' => 1], ['value' => 2]]],
            [
                'name' => 'required',
                'items.*.value' => 'exists:batch_test_users,lookup_value',
            ],
        )->stopOnFirstFailure();

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('name'));
        $this->assertFalse($validator->errors()->has('items.0.value'));
        $this->assertCount(0, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testPresenceBatchingRemainsEnabledWithoutStopOnFirstFailure(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['value' => 'Case']]],
            [
                'name' => 'required',
                'items.*.value' => 'exists:batch_test_users,lookup_value',
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('name'));
        $this->assertFalse($validator->errors()->has('items.0.value'));
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testTypelessSizePrefixRemainsBatchable(): void
    {
        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
            ]],
            ['items.*.email' => 'required|max:255|exists:batch_test_users,email'],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testUnusedExtensionDoesNotSuppressExclusionOrUnrelatedBatching(): void
    {
        $validator = $this->makeValidator(
            [
                'type' => 'section',
                'appointments' => [
                    ['email' => 'invalid-one'],
                    ['email' => 'invalid-two'],
                ],
                'items' => [['email' => 'user1@example.com']],
            ],
            [
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.email' => 'required|exists:batch_test_users,email',
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );
        $validator->addExtension('unused', static fn (): bool => true);

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testUsedMutatorSuppressesOnlyAffectedDescendantBatching(): void
    {
        $validator = $this->makeValidator(
            [
                'prepare' => true,
                'type' => 'section',
                'appointments' => [
                    ['email' => 'user1@example.com'],
                    ['email' => 'user2@example.com'],
                ],
                'items' => [['email' => 'user3@example.com']],
            ],
            [
                'prepare' => 'prepare_type',
                'appointments' => 'exclude_unless:type,chapter|required|array',
                'appointments.*.email' => 'required|exists:batch_test_users,email',
                'items.*.email' => 'required|exists:batch_test_users,email',
            ],
        );
        $validator->addExtension(
            'prepare_type',
            function (string $attribute, mixed $value, array $parameters, Validator $currentValidator): bool {
                $currentValidator->setValue('type', 'chapter');

                return true;
            },
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertTrue($result);
        $this->assertCount(3, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testValidatorAwareMutationFallsBackWithoutDisablingSafeSiblingBatching(): void
    {
        $validator = $this->makeValidator(
            ['items' => [[
                'safe_email' => 'new@example.com',
                'mutated_email' => 'user1@example.com',
            ]]],
            [
                'items.*.safe_email' => 'required|unique:batch_test_users,email',
                'items.*.mutated_email' => [
                    new class implements Rule, ValidatorAwareRule {
                        private Validator $validator;

                        public function setValidator(Validator $validator): static
                        {
                            $this->validator = $validator;

                            return $this;
                        }

                        public function passes(string $attribute, mixed $value): bool
                        {
                            $this->validator->setValue($attribute, 'user2@example.com');

                            return true;
                        }

                        public function message(): string
                        {
                            return 'The value could not be prepared.';
                        }
                    },
                    'unique:batch_test_users,email',
                ],
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertFalse($validator->errors()->has('items.0.safe_email'));
        $this->assertTrue($validator->errors()->has('items.0.mutated_email'));
        $this->assertCount(2, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    public function testValidatorAwareMutationCanConsumeAnotherSubmittedValueFact(): void
    {
        $validator = $this->makeValidator(
            ['items' => [[
                'submitted_email' => 'user2@example.com',
                'mutated_email' => 'user1@example.com',
            ]]],
            [
                'items.*.submitted_email' => 'required|unique:batch_test_users,email',
                'items.*.mutated_email' => [
                    new class implements Rule, ValidatorAwareRule {
                        private Validator $validator;

                        public function setValidator(Validator $validator): static
                        {
                            $this->validator = $validator;

                            return $this;
                        }

                        public function passes(string $attribute, mixed $value): bool
                        {
                            $this->validator->setValue($attribute, 'user2@example.com');

                            return true;
                        }

                        public function message(): string
                        {
                            return 'The value could not be prepared.';
                        }
                    },
                    'unique:batch_test_users,email',
                ],
            ],
        );

        DB::enableQueryLog();

        try {
            $result = $validator->passes();
            $queryLog = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }

        $this->assertFalse($result);
        $this->assertTrue($validator->errors()->has('items.0.submitted_email'));
        $this->assertTrue($validator->errors()->has('items.0.mutated_email'));
        $this->assertCount(1, array_filter(
            $queryLog,
            static fn (array $entry): bool => str_contains($entry['query'], 'batch_test_users'),
        ));
    }

    private function makeValidator(array $data, array $rules): Validator
    {
        $translator = new Translator(new ArrayLoader, 'en');
        $validator = new Validator($translator, $data, $rules);
        $validator->setPresenceVerifier($this->app->make('validation.presence'));

        return $validator;
    }
}

class BatchTestUser extends Model
{
    protected ?string $table = 'batch_test_users';
}

class CountingBatchTestUser extends Model
{
    public static int $constructions = 0;

    protected ?string $table = 'batch_test_users';

    public function __construct(array $attributes = [])
    {
        ++self::$constructions;

        parent::__construct($attributes);
    }
}

class ValidationPresenceDomainDate extends DateTimeImmutable implements Stringable
{
    public function __toString(): string
    {
        return $this->format(DATE_ATOM);
    }
}
