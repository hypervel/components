<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Validation\ValidationBatchDatabaseCheckerTest;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Translation\ArrayLoader;
use Hypervel\Translation\Translator;
use Hypervel\Validation\BatchDatabaseChecker;
use Hypervel\Validation\PrecomputedPresenceVerifier;
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

    public function testBuildVerifierReturnsNullWhenNoLookups()
    {
        $this->assertNull(BatchDatabaseChecker::buildVerifier([], null));
    }

    public function testDistinctCountSemanticsForArrayValuedExists()
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

    public function testBatchingActivatesEndToEndForStringFormExists()
    {
        $data = ['items' => []];
        for ($i = 0; $i < 10; ++$i) {
            $data['items'][] = ['email' => 'user' . (($i % 3) + 1) . '@example.com'];
        }

        $validator = $this->makeValidator($data, [
            'items.*.email' => 'required|exists:batch_test_users,email',
        ]);

        DB::enableQueryLog();
        $result = $validator->passes();
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($result);

        $existsQueries = array_filter($queryLog, function ($entry) {
            return str_contains($entry['query'], 'batch_test_users');
        });

        $this->assertLessThanOrEqual(2, count($existsQueries), 'Batching should collapse N exists queries into 1-2 batch queries');
    }

    public function testBatchingProducesCorrectPassFailResults()
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

    public function testOriginalPresenceVerifierIsRestoredAfterExceptionDuringBatchedValidation()
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

    public function testDifferentWildcardQueryShapesOnSameTableColumnFallBackToRealVerifier()
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

    public function testNonWildcardRuleBlocksBatchingForSameTableColumn()
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

    public function testArrayFormExistsRuleWithExtraConditionsBlocksBatchingForSameTableColumn()
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

    public function testFieldReferenceIgnoreIsNotBatched()
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

    public function testModelClassRuleResolvesCorrectly()
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

    public function testObjectFormExistsRulesBatchCorrectly()
    {
        $rule = new \Hypervel\Validation\Rules\Exists('batch_test_users', 'email');

        $validator = $this->makeValidator(
            ['items' => [
                ['email' => 'user1@example.com'],
                ['email' => 'user2@example.com'],
            ]],
            ['items.*.email' => ['required', $rule]],
        );

        $this->assertTrue($validator->passes());
    }

    public function testObjectFormUniqueRuleWithIgnoreBatchesCorrectly()
    {
        $rule = (new \Hypervel\Validation\Rules\Unique('batch_test_users', 'email'))
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

    public function testArrayFormExistsRuleBlocksBatchingForSameTableColumn()
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

    public function testNonBatchableStringWildcardRuleDoesNotCorruptResults()
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

    public function testObjectFormExistsWithInferredColumnAndDifferentShapeBlocksBatchingForSameTableColumn()
    {
        $rule = (new \Hypervel\Validation\Rules\Exists('batch_test_users'))
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
