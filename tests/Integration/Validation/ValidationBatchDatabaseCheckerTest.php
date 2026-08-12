<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\ValidationBatchDatabaseCheckerTest;

use Closure;
use Hypervel\Contracts\Validation\Rule;
use Hypervel\Contracts\Validation\ValidatorAwareRule;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
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

class ValidationBatchDatabaseCheckerTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('batch_test_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('status')->default('active');
        });

        $this->app->make('db')->table('batch_test_users')->insert([
            ['email' => 'user1@example.com', 'status' => 'active'],
            ['email' => 'user2@example.com', 'status' => 'active'],
            ['email' => 'user3@example.com', 'status' => 'inactive'],
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
        $verifier = new PrecomputedPresenceVerifier;
        $verifier->addLookup('batch_test_users', 'email', ['user1@example.com']);

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

        $this->assertLessThanOrEqual(2, count($existsQueries), 'Batching should collapse N exists queries into 1-2 batch queries');
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

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('items.1.email'));
        $this->assertFalse($validator->errors()->has('items.0.email'));
        $this->assertFalse($validator->errors()->has('items.2.email'));
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

    public function testDifferentWildcardQueryShapesOnSameTableColumnFallBackToRealVerifier(): void
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

        $this->assertTrue($validator->passes());
    }

    public function testNonWildcardRuleBlocksBatchingForSameTableColumn(): void
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

        $this->assertTrue($validator->passes());
    }

    public function testArrayFormExistsRuleWithExtraConditionsBlocksBatchingForSameTableColumn(): void
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

        // Should validate correctly via the per-item path (not batched)
        $this->assertTrue($validator->passes());
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

        $this->assertTrue($validator->passes());
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

    public function testStringFormUniqueRuleUnescapesIgnoredValueBeforeBatching(): void
    {
        $email = 'slash\id@example.com';

        $this->app->make('db')->table('batch_test_users')->insert([
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

    public function testArrayFormExistsRuleBlocksBatchingForSameTableColumn(): void
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

        $this->assertTrue($validator->passes());
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

    public function testObjectFormExistsWithInferredColumnAndDifferentShapeBlocksBatchingForSameTableColumn(): void
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

    public function testValidatorAwareMutationDisablesPresencePrecomputation(): void
    {
        $validator = $this->makeValidator(
            ['items' => [['email' => 'user1@example.com']]],
            ['items.*.email' => [
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
            ]],
        );

        $this->assertFalse($validator->passes());
        $this->assertTrue($validator->errors()->has('items.0.email'));
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
