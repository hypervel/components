<?php

declare(strict_types=1);

namespace Hypervel\Validation\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Contracts\Validation\CompilableRules;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Support\Arr;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Validation\DatabasePresenceVerifier;
use Hypervel\Validation\Rule;
use Hypervel\Validation\RulePlanCache;
use Hypervel\Validation\ValidationData;
use Hypervel\Validation\ValidationRuleParser;
use Hypervel\Validation\Validator;
use LogicException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

/**
 * Run validation performance benchmarks.
 *
 * Measures the compiled execution path against a legacy baseline that uses
 * the pre-rewrite validateAttribute() loop. Reports per-scenario timings
 * with speedup multipliers.
 */
#[AsCommand(name: 'validation:benchmark')]
class BenchmarkValidationCommand extends Command
{
    /**
     * The console command name.
     */
    protected ?string $name = 'validation:benchmark';

    /**
     * The console command description.
     */
    protected string $description = 'Run validation performance benchmarks';

    /**
     * Scenario descriptions for the output header.
     *
     * @var array<string, string>
     */
    private const array SCENARIO_DESCRIPTIONS = [
        'simple' => '500 items × 7 fields (string, email, integer, in, alpha_num, numeric, nullable)',
        'nested' => '1,000 orders × 5 nested line items (string, integer, numeric)',
        'conditional' => '100 items × 47 conditional fields (exclude_unless, string, max)',
        'flat' => '3-field login form (email, string, boolean)',
        'fluent' => '500 items × 2 fluent-rule fields (Rule::in, Rule::notIn, max)',
        'typeless-size' => '500 items × 3 typeless size fields (string and array min, max, between, size)',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scenarios = trim((string) $this->option('scenarios'));
        $scenarioList = $scenarios === 'all'
            ? array_keys(self::SCENARIO_DESCRIPTIONS)
            : array_map(trim(...), explode(',', $scenarios));

        foreach ($scenarioList as $scenario) {
            if (! array_key_exists($scenario, self::SCENARIO_DESCRIPTIONS)) {
                $this->error("Invalid scenario: {$scenario}. Available: " . implode(', ', array_keys(self::SCENARIO_DESCRIPTIONS)));

                return self::FAILURE;
            }
        }

        $iterationsOption = $this->option('iterations');
        $iterations = filter_var($iterationsOption, FILTER_VALIDATE_INT);

        if ($iterations === false || $iterations < 1) {
            $this->error("Invalid iterations: {$iterationsOption}. Must be a positive integer.");

            return self::FAILURE;
        }

        $this->components->info('Hypervel Validation Benchmark');

        /** @var Translator $translator */
        $translator = $this->hypervel->make('translator');
        /** @var ConnectionResolverInterface $database */
        $database = $this->hypervel->make('db');
        $presenceVerifier = new DatabasePresenceVerifier($database);
        $results = [];

        foreach ($scenarioList as $scenario) {
            $description = self::SCENARIO_DESCRIPTIONS[$scenario];

            $this->line("  <fg=cyan>Benchmarking</> {$scenario} <fg=gray>({$description})</>");

            [$data, $rules] = $this->buildScenario($scenario);

            RulePlanCache::flushState();
            ValidationRuleParser::flushState();
            $optimizedPassed = $this->makeValidator(
                Validator::class,
                $translator,
                $data,
                $rules,
                $presenceVerifier,
            )->passes();
            $optimizedMs = $this->benchmark(
                fn () => $this->makeValidator(
                    Validator::class,
                    $translator,
                    $data,
                    $rules,
                    $presenceVerifier,
                )->passes(),
                $iterations,
            );

            RulePlanCache::flushState();
            ValidationRuleParser::flushState();
            $legacyPassed = $this->makeValidator(
                LegacyValidator::class,
                $translator,
                $data,
                $rules,
                $presenceVerifier,
            )->passes();

            if ($optimizedPassed !== $legacyPassed) {
                $this->error("Optimized and legacy validation disagree for scenario: {$scenario}.");

                return self::FAILURE;
            }

            $legacyMs = $this->benchmark(
                fn () => $this->makeValidator(
                    LegacyValidator::class,
                    $translator,
                    $data,
                    $rules,
                    $presenceVerifier,
                )->passes(),
                $iterations,
            );

            $speedup = round($legacyMs / $optimizedMs, 1);

            $results[] = [
                $scenario,
                number_format($optimizedMs, 2) . ' ms',
                number_format($legacyMs, 2) . ' ms',
                $speedup . '×',
            ];
        }

        $this->newLine();
        $this->table(['Scenario', 'Optimized', 'Legacy', 'Speedup'], $results);
        $this->newLine();

        $this->components->bulletList([
            '<fg=gray>Optimized</> — compiled execution with inline checks, plan caching, pre-evaluated excludes',
            '<fg=gray>Legacy</> — pre-optimization baseline with original validateAttribute() loop and O(n²) wildcard expansion',
            '<fg=gray>Timings</> — median of ' . $iterations . ' measured ' . ($iterations === 1 ? 'iteration' : 'iterations') . ' after one untimed warmup',
            '<fg=gray>Speedup</> — how many times faster the optimized path is (higher is better)',
        ]);

        return self::SUCCESS;
    }

    /**
     * Run a callable N times and return the median time in milliseconds.
     */
    private function benchmark(callable $callback, int $iterations): float
    {
        $times = [];

        for ($i = 0; $i < $iterations; ++$i) {
            $start = hrtime(true);
            $callback();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($times);
        $middle = intdiv(count($times), 2);

        return count($times) % 2 === 0
            ? ($times[$middle - 1] + $times[$middle]) / 2
            : $times[$middle];
    }

    /**
     * Build a validator with production presence-verifier wiring.
     *
     * @param class-string<Validator> $validatorClass
     */
    private function makeValidator(
        string $validatorClass,
        Translator $translator,
        array $data,
        array $rules,
        DatabasePresenceVerifier $presenceVerifier,
    ): Validator {
        $validator = new $validatorClass($translator, $data, $rules);
        $validator->setPresenceVerifier($presenceVerifier);

        return $validator;
    }

    /**
     * Build the data and rules for a benchmark scenario.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function buildScenario(string $name): array
    {
        return match ($name) {
            'simple' => $this->simpleScenario(),
            'nested' => $this->nestedScenario(),
            'conditional' => $this->conditionalScenario(),
            'flat' => $this->flatScenario(),
            'fluent' => $this->fluentScenario(),
            'typeless-size' => $this->typelessSizeScenario(),
            default => throw new LogicException("Unknown benchmark scenario [{$name}]."),
        };
    }

    /**
     * 500 items × 7 simple fields.
     */
    private function simpleScenario(): array
    {
        $items = [];
        for ($i = 0; $i < 500; ++$i) {
            $items[] = [
                'name' => 'Item ' . $i,
                'email' => "user{$i}@example.com",
                'age' => 18 + ($i % 63),
                'status' => 'active',
                'code' => 'ABC' . $i,
                'score' => 1 + ($i % 100),
                'notes' => 'Some notes for item ' . $i,
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
     * 1000 orders × 5 nested line items.
     */
    private function nestedScenario(): array
    {
        $orders = [];
        for ($i = 0; $i < 1000; ++$i) {
            $items = [];
            for ($j = 0; $j < 5; ++$j) {
                $items[] = [
                    'sku' => 'SKU-' . $i . '-' . $j,
                    'quantity' => 1 + (($i + $j) % 10),
                    'price' => (100 + (($i * 5 + $j) % 9901)) / 100,
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
     * 100 items × 47 conditional fields.
     */
    private function conditionalScenario(): array
    {
        $items = [];
        for ($i = 0; $i < 100; ++$i) {
            $item = ['type' => $i % 3 === 0 ? 'chapter' : 'section'];
            for ($j = 0; $j < 47; ++$j) {
                $item["field_{$j}"] = "value_{$j}";
            }
            $items[] = $item;
        }

        $rules = ['items.*.type' => 'required|string|in:chapter,section'];
        for ($j = 0; $j < 47; ++$j) {
            $rules["items.*.field_{$j}"] = 'exclude_unless:items.*.type,chapter|required|string|max:255';
        }

        return [['items' => $items], $rules];
    }

    /**
     * 3-field flat login form.
     */
    private function flatScenario(): array
    {
        return [
            ['email' => 'user@example.com', 'password' => 'secret123', 'remember' => true],
            ['email' => 'required|email', 'password' => 'required|string|min:8', 'remember' => 'boolean'],
        ];
    }

    /**
     * 500 items × 2 fluent-rule fields.
     */
    private function fluentScenario(): array
    {
        $statuses = ['active', 'inactive', 'pending'];
        $items = [];

        for ($index = 0; $index < 500; ++$index) {
            $items[] = [
                'status' => $statuses[$index % count($statuses)],
                'role' => $index % 2 === 0 ? 'member' : 'editor',
            ];
        }

        return [
            ['items' => $items],
            [
                'items.*.status' => ['required', Rule::in($statuses), 'max:16'],
                'items.*.role' => ['required', Rule::notIn(['blocked', 'banned']), 'max:16'],
            ],
        ];
    }

    /**
     * 500 items × 3 typeless size fields.
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

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['scenarios', null, InputOption::VALUE_REQUIRED, 'Comma-separated scenario names or "all"', 'all'],
            ['iterations', null, InputOption::VALUE_REQUIRED, 'Number of iterations per scenario', '5'],
        ];
    }
}

/**
 * Baseline validator using the pre-rewrite validateAttribute() loop AND
 * the original O(n²) wildcard expansion.
 *
 * Provides an accurate performance comparison against the full optimized
 * path by reverting BOTH the execution model and the wildcard expansion.
 */
final class LegacyValidator extends Validator
{
    /**
     * Override addRules to use the legacy O(n²) wildcard expansion parser.
     */
    public function addRules(array $rules): void
    {
        $response = (new LegacyValidationRuleParser($this->data))
            ->explode(ValidationRuleParser::filterConditionalRules($rules, $this->data));

        foreach ($response->rules as $key => $rule) {
            $this->rules[$key] = array_merge($this->rules[$key] ?? [], $rule);
        }

        $this->implicitAttributes = array_merge(
            $this->implicitAttributes,
            $response->implicitAttributes
        );
    }

    /**
     * Determine if the data passes the validation rules using the legacy path.
     */
    public function passes(): bool
    {
        $this->messages = new MessageBag;
        [$this->distinctValues, $this->failedRules, $this->excludeAttributes] = [[], [], []];

        foreach ($this->rules as $attribute => $rules) {
            $attribute = (string) $attribute;
            if ($this->shouldBeExcluded($attribute)) {
                $this->removeAttribute($attribute);
                continue;
            }

            if ($this->stopOnFirstFailure && $this->messages->isNotEmpty()) {
                break;
            }

            foreach ($rules as $rule) {
                $this->validateAttribute($attribute, $rule);

                if ($this->shouldBeExcluded($attribute)) {
                    break;
                }

                if ($this->shouldStopValidating($attribute)) {
                    break;
                }
            }
        }

        foreach ($this->rules as $attribute => $rules) {
            $attribute = (string) $attribute;
            if ($this->shouldBeExcluded($attribute)) {
                $this->removeAttribute($attribute);
            }
        }

        foreach ($this->after as $after) {
            $after();
        }

        return $this->messages->isEmpty();
    }
}

/**
 * Parser with the original O(n²) wildcard expansion for baseline benchmarks.
 *
 * Uses Arr::dot() + regex matching instead of the tree-walk approach,
 * reproducing the pre-optimization wildcard expansion behavior.
 */
final class LegacyValidationRuleParser extends ValidationRuleParser
{
    /**
     * Original O(n²) wildcard expansion via Arr::dot() + regex.
     */
    protected function explodeWildcardRules(array $results, string $attribute, array|object|string $rules): array
    {
        $pattern = str_replace('\*', '[^\.]*', preg_quote($attribute, '/'));

        $data = ValidationData::initializeAndGatherData($attribute, $this->data);

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if (Str::startsWith($key, $attribute) || (bool) preg_match('/^' . $pattern . '\z/', $key)) {
                foreach ((array) $rules as $rule) {
                    if ($rule instanceof CompilableRules) {
                        $context = Arr::get($this->data, Str::beforeLast($key, '.'));

                        $compiled = $rule->compile($key, $value, $data, $context);

                        $this->implicitAttributes = array_merge_recursive(
                            $compiled->implicitAttributes,
                            $this->implicitAttributes,
                            [$attribute => [$key]]
                        );

                        $results = $this->mergeRules($results, $compiled->rules);
                    } else {
                        $this->implicitAttributes[$attribute][] = $key;

                        $results = $this->mergeRules($results, $key, $rule);
                    }
                }
            }
        }

        return $results;
    }
}
