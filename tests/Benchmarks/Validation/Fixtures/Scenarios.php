<?php

declare(strict_types=1);

namespace Hypervel\Tests\Benchmarks\Validation\Fixtures;

use Closure;
use Hypervel\Database\Query\Builder;
use Hypervel\Validation\Rule;

class Scenarios
{
    /**
     * Build fixtures outside the measured operations.
     *
     * @return array<string, array{data: array, rules: Closure, failures: list<string>, scale: int, cold: bool, builders: string}>
     */
    public function all(): array
    {
        $scenarios = [];

        foreach (['simple' => 500, 'nested' => 5000, 'conditional' => 4700, 'flat' => 1, 'fluent' => 500, 'typeless-size' => 500] as $name => $scale) {
            [$data, $rules] = match ($name) {
                'simple' => $this->simpleScenario(),
                'nested' => $this->nestedScenario(),
                'conditional' => $this->conditionalScenario(),
                'flat' => $this->flatScenario(),
                'fluent' => $this->fluentScenario(),
                'typeless-size' => $this->typelessSizeScenario(),
            };
            $scenarios[$name] = $this->scenario($data, $rules, [], $scale);
        }

        $reused = $scenarios['fluent']['rules']();
        $scenarios['fluent-reused'] = $this->scenario($scenarios['fluent']['data'], $reused, [], 500);
        $scenarios['fluent-reused']['builders'] = 'reused';

        foreach ([false, true] as $strings) {
            $items = [];
            for ($index = 0; $index < 500; ++$index) {
                $items[] = ['amount' => $strings ? '12.25' : 12.25, 'count' => $strings ? '1234' : 1234];
            }
            $scenarios[$strings ? 'numeric-strings' : 'numeric-floats'] = $this->scenario(
                ['items' => $items],
                static fn (): array => [
                    'items.*.amount' => [Rule::numeric()->min(0)->max(99.99), 'decimal:2'],
                    'items.*.count' => ['integer', 'digits:4'],
                ],
                [],
                500,
            );
        }

        $scenarios['numeric-precision'] = $this->scenario(
            ['amount' => '1.23456789012348'],
            static fn (): array => ['amount' => [Rule::numeric()->max(1.234567890123456)]],
            ['amount'],
        );

        return [...$scenarios, ...$this->databaseScenarios()];
    }

    /**
     * Describe one workload and its expected failed attributes.
     */
    private function scenario(array $data, array|Closure $rules, array $failures = [], int $scale = 1): array
    {
        return [
            'data' => $data,
            'rules' => $rules instanceof Closure ? $rules : static fn (): array => $rules,
            'failures' => $failures,
            'scale' => $scale,
            'cold' => false,
            'builders' => $rules instanceof Closure ? 'fresh' : 'string',
        ];
    }

    /**
     * Exercise ordinary, structured and fallback database checks with the same public APIs on both revisions.
     */
    private function databaseScenarios(): array
    {
        $scenarios = [];
        $scenarios['database-flat'] = $this->scenario(
            ['email' => 'new@example.com', 'code' => 'code-1', 'identifier' => 1],
            static fn (): array => [
                'email' => 'required|unique:validation_benchmark_users,email',
                'code' => ['required', Rule::unique('validation_benchmark_users', 'code')->ignore(1)],
                'identifier' => ['required', Rule::exists('validation_benchmark_users', 'id')],
            ],
        );

        foreach (['hits', 'mixed', 'chunked', 'conditions', 'subclass', 'callback', 'floats'] as $kind) {
            $items = [];
            $failures = [];
            $size = $kind === 'chunked' ? 1200 : 100;
            for ($index = 0; $index < $size; ++$index) {
                $identifier = $index + 1;
                $missing = $kind === 'mixed' && $index % 10 === 0;
                $items[] = [
                    'code' => $missing ? 'missing-' . $identifier : 'code-' . $identifier,
                    'unique_code' => $index % 10 === 0 ? 'code-' . $identifier : 'unused-' . $identifier,
                    'amount' => $identifier + 0.25,
                ];
                if ($missing) {
                    $failures[] = "items.{$index}.code";
                }
                if ($kind === 'mixed' && $index % 10 === 0) {
                    $failures[] = "items.{$index}.unique_code";
                }
            }
            // Duplicate candidates exercise deduplication without changing expected validity.
            if ($kind === 'hits') {
                $items = [...$items, ...$items];
            }
            $rules = static function () use ($kind): array {
                $rule = $kind === 'subclass'
                    ? new BenchmarkExists('validation_benchmark_users', 'code')
                    : Rule::exists('validation_benchmark_users', $kind === 'floats' ? 'amount' : 'code');
                if ($kind === 'conditions') {
                    $rule->where('status', 'active')->whereNot('tenant', 2)
                        ->whereNull('deleted_at')->whereNotNull('email')
                        ->whereIn('status', ['active', 'pending'])->whereNotIn('tenant', [2, 3]);
                } elseif ($kind === 'callback') {
                    $rule->using(static fn (Builder $query): Builder => $query->where('status', 'active'));
                }
                $result = ['items.*.' . ($kind === 'floats' ? 'amount' : 'code') => ['required', $rule]];
                if ($kind === 'mixed') {
                    $result['items.*.unique_code'] = ['required', Rule::unique('validation_benchmark_users', 'code')];
                }

                return $result;
            };
            $scenarios['database-' . $kind] = $this->scenario(['items' => $items], $rules, $failures, count($items));
        }

        $scenarios['database-fixed-conditions'] = $this->scenario(
            ['literal' => 'code-1', 'marker' => 'code-1', 'repeated' => 'code-1', 'json' => 'code-1'],
            static fn (): array => [
                'literal' => [Rule::exists('validation_benchmark_users', 'code')->where('literal', 'NULL')],
                'marker' => [Rule::exists('validation_benchmark_users', 'code')->where('marker', '!other')],
                'repeated' => [Rule::exists('validation_benchmark_users', 'code')->where('status', 'inactive')->where('status', 'active')],
                'json' => [Rule::exists('validation_benchmark_users', 'code')->where('metadata->enabled', true)->whereNot('metadata->disabled', true)],
            ],
            ['marker', 'repeated'],
        );
        $scenarios['database-precision'] = $this->scenario(
            ['amount' => 1.234567890123456],
            static fn (): array => ['amount' => [Rule::exists('validation_benchmark_users', 'amount')]],
        );

        return $scenarios;
    }

    /**
     * Build 500 items with seven simple fields.
     */
    private function simpleScenario(): array
    {
        $items = [];
        for ($index = 0; $index < 500; ++$index) {
            $items[] = [
                'name' => 'Item ' . $index,
                'email' => "user{$index}@example.com",
                'age' => 18 + ($index % 63),
                'status' => 'active',
                'code' => 'ABC' . $index,
                'score' => 1 + ($index % 100),
                'notes' => 'Some notes for item ' . $index,
            ];
        }

        return [
            ['items' => $items],
            [
                'items.*.name' => 'required|string|max:255',
                'items.*.email' => 'required|email',
                'items.*.age' => 'required|integer|min:0|max:150',
                'items.*.status' => 'required|in:active,inactive,pending',
                'items.*.code' => 'required|string|alpha_num|max:20',
                'items.*.score' => 'required|numeric|between:0,100',
                'items.*.notes' => 'nullable|string|max:1000',
            ],
        ];
    }

    /**
     * Build 1000 orders with five nested line items.
     */
    private function nestedScenario(): array
    {
        $orders = [];
        for ($index = 0; $index < 1000; ++$index) {
            $items = [];
            for ($itemIndex = 0; $itemIndex < 5; ++$itemIndex) {
                $items[] = [
                    'sku' => 'SKU-' . $index . '-' . $itemIndex,
                    'quantity' => 1 + (($index + $itemIndex) % 10),
                    'price' => (100 + (($index * 5 + $itemIndex) % 9901)) / 100,
                ];
            }
            $orders[] = ['items' => $items];
        }

        return [
            ['orders' => $orders],
            [
                'orders.*.items.*.sku' => 'required|string',
                'orders.*.items.*.quantity' => 'required|integer|min:1',
                'orders.*.items.*.price' => 'required|numeric|min:0',
            ],
        ];
    }

    /**
     * Build 100 items with 47 conditional fields.
     */
    private function conditionalScenario(): array
    {
        $items = [];
        for ($index = 0; $index < 100; ++$index) {
            $item = ['type' => $index % 3 === 0 ? 'chapter' : 'section'];
            for ($itemIndex = 0; $itemIndex < 47; ++$itemIndex) {
                $item["field_{$itemIndex}"] = "value_{$itemIndex}";
            }
            $items[] = $item;
        }

        $rules = ['items.*.type' => 'required|string|in:chapter,section'];
        for ($itemIndex = 0; $itemIndex < 47; ++$itemIndex) {
            $rules["items.*.field_{$itemIndex}"] = 'exclude_unless:items.*.type,chapter|required|string|max:255';
        }

        return [['items' => $items], $rules];
    }

    /**
     * Build a three-field login form.
     */
    private function flatScenario(): array
    {
        return [
            ['email' => 'user@example.com', 'password' => 'secret123', 'remember' => true],
            ['email' => 'required|email', 'password' => 'required|string|min:8', 'remember' => 'boolean'],
        ];
    }

    /**
     * Build 500 items with fresh shared membership builders and form-string identifiers.
     */
    private function fluentScenario(): array
    {
        $statuses = ['active', 'inactive', 'pending'];
        $items = [];

        for ($index = 0; $index < 500; ++$index) {
            $items[] = [
                'status' => $statuses[$index % count($statuses)],
                'role' => $index % 2 === 0 ? 'member' : 'editor',
                'allowed_id' => (string) (1 + $index % 3),
            ];
        }

        return [
            ['items' => $items],
            static fn (): array => [
                'items.*.status' => ['required', Rule::in($statuses), 'max:16'],
                'items.*.role' => ['required', Rule::notIn(['blocked', 'banned']), 'max:16'],
                'items.*.allowed_id' => ['required', Rule::in([1, 2, 3])],
            ],
        ];
    }

    /**
     * Build 500 items with three typeless size fields.
     */
    private function typelessSizeScenario(): array
    {
        $items = [];

        for ($index = 0; $index < 500; ++$index) {
            $items[] = [
                'name' => "Item {$index}",
                'code' => "CODE-{$index}",
                'tags' => ['alpha', 'beta', 'gamma'],
            ];
        }

        return [
            ['items' => $items],
            [
                'items.*.name' => 'required|min:2|max:255|between:2,255',
                'items.*.code' => 'required|min:2|max:32|between:2,32',
                'items.*.tags' => 'required|min:1|max:5|between:1,5|size:3',
            ],
        ];
    }
}
