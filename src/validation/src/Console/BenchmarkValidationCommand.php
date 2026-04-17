<?php

declare(strict_types=1);

namespace Hypervel\Validation\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Validation\CompilableRules;
use Hypervel\Support\Arr;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Validation\RulePlanCache;
use Hypervel\Validation\ValidationData;
use Hypervel\Validation\ValidationRuleParser;
use Hypervel\Validation\Validator;
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
    private static array $scenarioDescriptions = [
        'simple' => '500 items × 7 fields (string, email, integer, in, alpha_num, numeric, nullable)',
        'nested' => '1,000 orders × 5 nested line items (string, integer, numeric)',
        'conditional' => '100 items × 47 conditional fields (exclude_unless, string, max)',
        'flat' => '3-field login form (email, string, boolean)',
    ];

    /**
     * The number of iterations per scenario.
     */
    private static int $iterations = 0;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $scenarios = $this->option('scenarios');
        $scenarioList = $scenarios === 'all'
            ? ['simple', 'nested', 'conditional', 'flat']
            : explode(',', $scenarios);

        self::$iterations = max(1, (int) $this->option('iterations'));

        $this->components->info('Hypervel Validation Benchmark');

        $translator = $this->hypervel->make('translator');
        $results = [];

        foreach ($scenarioList as $scenario) {
            $scenario = trim($scenario);
            $description = self::$scenarioDescriptions[$scenario] ?? '';

            $this->line("  <fg=cyan>Benchmarking</> {$scenario} <fg=gray>({$description})</>");

            [$data, $rules] = $this->buildScenario($scenario);

            RulePlanCache::flushState();
            $optimizedMs = $this->benchmark(fn () => (new Validator($translator, $data, $rules))->passes());

            RulePlanCache::flushState();
            $legacyMs = $this->benchmark(fn () => (new LegacyValidator($translator, $data, $rules))->passes());

            $speedup = $legacyMs > 0 ? round($legacyMs / $optimizedMs, 1) : 0;

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
            '<fg=gray>Legacy</> — original validateAttribute() loop (still includes O(n) wildcard tree walk)',
            '<fg=gray>Speedup</> — how many times faster the optimized path is (higher is better)',
        ]);

        return 0;
    }

    /**
     * Run a callable N times and return the median time in milliseconds.
     */
    private function benchmark(callable $callback): float
    {
        $times = [];

        for ($i = 0; $i < self::$iterations; ++$i) {
            $start = hrtime(true);
            $callback();
            $times[] = (hrtime(true) - $start) / 1_000_000;
        }

        sort($times);

        return $times[(int) (count($times) / 2)];
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
            default => $this->simpleScenario(),
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
                'age' => rand(18, 80),
                'status' => 'active',
                'code' => 'ABC' . $i,
                'score' => rand(1, 100),
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
                    'quantity' => rand(1, 10),
                    'price' => rand(100, 10000) / 100,
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
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['scenarios', null, InputOption::VALUE_OPTIONAL, 'Comma-separated scenario names or "all"', 'all'],
            ['iterations', null, InputOption::VALUE_OPTIONAL, 'Number of iterations per scenario', '5'],
        ];
    }

    /**
     * Flush global state.
     */
    public static function flushState(): void
    {
        self::$iterations = 0;
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
