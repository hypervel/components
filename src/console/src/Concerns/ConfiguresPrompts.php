<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Hypervel\Console\PromptValidationException;
use Hypervel\Prompts\AutoCompletePrompt;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\DataTablePrompt;
use Hypervel\Prompts\MultiSearchPrompt;
use Hypervel\Prompts\MultiSelectPrompt;
use Hypervel\Prompts\NumberPrompt;
use Hypervel\Prompts\PasswordPrompt;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SearchPrompt;
use Hypervel\Prompts\SelectPrompt;
use Hypervel\Prompts\SuggestPrompt;
use Hypervel\Prompts\TextareaPrompt;
use Hypervel\Prompts\TextPrompt;
use Hypervel\Support\Collection;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Input\InputInterface;

trait ConfiguresPrompts
{
    /**
     * Configure the prompt fallbacks.
     */
    protected function configurePrompts(InputInterface $input): void
    {
        Prompt::setOutput($this->output);

        Prompt::interactive(($input->isInteractive() && defined('STDIN') && stream_isatty(STDIN)) || $this->hypervel->runningUnitTests());

        Prompt::validateUsing(fn (Prompt $prompt, mixed $value) => $this->validatePrompt($value, $prompt->validate));

        if (windows_os() || $this->hypervel->runningUnitTests()) {
            Prompt::fallbackWhen(true);
        }

        TextPrompt::fallbackUsing(fn (TextPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->components->ask($prompt->label, $prompt->default === '' ? null : $prompt->default) ?? '',
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        TextareaPrompt::fallbackUsing(fn (TextareaPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->components->ask($prompt->label, $prompt->default === '' ? null : $prompt->default, multiline: true) ?? '',
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        NumberPrompt::fallbackUsing(fn (NumberPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer($prompt, $this->numberFallback($prompt)),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        PasswordPrompt::fallbackUsing(fn (PasswordPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer($prompt, $this->components->secret($prompt->label) ?? ''),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        PausePrompt::fallbackUsing(fn (PausePrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt): mixed {
                $this->components->ask($prompt->message);

                return $this->transformFallbackAnswer($prompt, $prompt->value());
            },
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        ConfirmPrompt::fallbackUsing(fn (ConfirmPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->components->confirm($prompt->label, $prompt->default),
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        SelectPrompt::fallbackUsing(fn (SelectPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->selectFallback($prompt->label, $prompt->options, $prompt->default),
            ),
            false,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        MultiSelectPrompt::fallbackUsing(fn (MultiSelectPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->multiselectFallback($prompt->label, $prompt->options, $prompt->default, $prompt->required),
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        DataTablePrompt::fallbackUsing(fn (DataTablePrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer($prompt, $this->datatableFallback($prompt)),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        SuggestPrompt::fallbackUsing(fn (SuggestPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->components->askWithCompletion(
                    $prompt->label,
                    $this->completionOptions($prompt->options),
                    $prompt->default === '' ? null : $prompt->default,
                ) ?? '',
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        AutoCompletePrompt::fallbackUsing(fn (AutoCompletePrompt $prompt) => $this->promptUntilValid(
            fn () => $this->transformFallbackAnswer(
                $prompt,
                $this->components->askWithCompletion(
                    $prompt->label,
                    $this->completionOptions($prompt->options),
                    $prompt->default === '' ? null : $prompt->default,
                ) ?? '',
            ),
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        SearchPrompt::fallbackUsing(fn (SearchPrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt): mixed {
                $query = $this->components->ask($prompt->label);

                $options = ($prompt->options)($query);

                return $this->transformFallbackAnswer($prompt, $this->selectFallback($prompt->label, $options));
            },
            false,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));

        MultiSearchPrompt::fallbackUsing(fn (MultiSearchPrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt): mixed {
                $query = $this->components->ask($prompt->label);

                $options = ($prompt->options)($query);

                return $this->transformFallbackAnswer(
                    $prompt,
                    $this->multiselectFallback($prompt->label, $options, required: $prompt->required),
                );
            },
            $prompt->required,
            fn (mixed $value) => $this->validateFallbackPrompt($prompt, $value),
        ));
    }

    /**
     * Prompt the user until the given validation callback passes.
     *
     * @template PResult
     *
     * @param Closure(): PResult $prompt
     * @param bool|string $required
     * @param null|(Closure(PResult): mixed) $validate
     * @return PResult
     */
    protected function promptUntilValid($prompt, $required, $validate)
    {
        while (true) {
            $result = $prompt();

            // Keep this empty set synchronized with Prompt::isInvalidWhenRequired().
            if ($required !== false && ($result === '' || $result === [] || $result === false || $result === null)) {
                $this->components->error(is_string($required) && strlen($required) > 0 ? $required : 'Required.');

                if ($this->hypervel->runningUnitTests()) {
                    throw new PromptValidationException;
                }
                continue;
            }

            $error = is_callable($validate) ? $validate($result) : $this->validatePrompt($result, $validate);

            if (is_string($error) && strlen($error) > 0) {
                $this->components->error($error);

                if ($this->hypervel->runningUnitTests()) {
                    throw new PromptValidationException;
                }
                continue;
            }

            return $result;
        }
    }

    /**
     * Transform a fallback answer.
     */
    private function transformFallbackAnswer(Prompt $prompt, mixed $answer): mixed
    {
        return $prompt->transform === null ? $answer : ($prompt->transform)($answer);
    }

    /**
     * Validate a fallback answer.
     */
    private function validateFallbackPrompt(Prompt $prompt, mixed $value): ?string
    {
        $intrinsicError = $prompt->validateIntrinsic($value);

        if (is_string($intrinsicError) && $intrinsicError !== '') {
            return $intrinsicError;
        }

        $error = is_callable($prompt->validate)
            ? ($prompt->validate)($value)
            : $this->validatePrompt($value, $prompt->validate);

        if (! is_string($error) && $error !== null) {
            throw new RuntimeException('The validator must return a string or null.');
        }

        return $error;
    }

    /**
     * Validate the given prompt value using the validator.
     *
     * @param mixed $value
     * @param mixed $rules
     * @return ?string
     */
    protected function validatePrompt($value, $rules)
    {
        if ($rules instanceof stdClass) {
            $messages = $rules->messages ?? [];
            $attributes = $rules->attributes ?? [];
            $rules = $rules->rules ?? null;
        }

        if (! $rules) {
            return null;
        }

        $field = 'answer';

        if (is_array($rules) && ! array_is_list($rules)) {
            [$field, $rules] = [key($rules), current($rules)];
        }

        return $this->getPromptValidatorInstance(
            $field,
            $value,
            $rules,
            $messages ?? [],
            $attributes ?? []
        )->errors()->first();
    }

    /**
     * Get the validator instance that should be used to validate prompts.
     *
     * @param mixed $field
     * @param mixed $value
     * @param mixed $rules
     * @return \Hypervel\Validation\Validator
     */
    protected function getPromptValidatorInstance($field, $value, $rules, array $messages = [], array $attributes = [])
    {
        return $this->hypervel->make('validator')->make(
            [$field => $value],
            [$field => $rules],
            empty($messages) ? $this->validationMessages() : $messages,
            empty($attributes) ? $this->validationAttributes() : $attributes,
        );
    }

    /**
     * Get the validation messages that should be used during prompt validation.
     *
     * @return array<string, string>
     */
    protected function validationMessages(): array
    {
        return [];
    }

    /**
     * Get the validation attributes that should be used during prompt validation.
     *
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [];
    }

    /**
     * Number fallback.
     */
    private function numberFallback(NumberPrompt $prompt): int|string
    {
        $answer = $this->components->ask(
            $prompt->label,
            $prompt->default === '' ? null : $prompt->default,
        ) ?? '';

        return NumberPrompt::parseInteger((string) $answer) ?? (string) $answer;
    }

    /**
     * Select fallback.
     *
     * @param string $label
     * @param array<array-key, string> $options
     * @param null|int|string $default
     * @return int|string
     */
    private function selectFallback($label, $options, $default = null)
    {
        $answer = $this->components->choice($label, $options, $default);

        if (! array_is_list($options) && $answer === (string) (int) $answer) {
            return (int) $answer;
        }

        return $answer;
    }

    /**
     * Multi-select fallback.
     *
     * @param string $label
     * @param array $options
     * @param array $default
     * @param bool|string $required
     * @return array
     */
    private function multiselectFallback($label, $options, $default = [], $required = false)
    {
        $default = $default !== [] ? implode(',', $default) : null;

        if ($required === false && ! $this->hypervel->runningUnitTests()) {
            $options = array_is_list($options)
                ? ['None', ...$options]
                : ['' => 'None'] + $options;

            if ($default === null) {
                $default = 'None';
            }
        }

        $answers = $this->components->choice($label, $options, $default, null, true);

        if (! array_is_list($options)) {
            $answers = array_map(fn ($value) => $value === (string) (int) $value ? (int) $value : $value, $answers);
        }

        if ($required === false) {
            return array_is_list($options)
                ? array_values(array_filter($answers, fn ($value) => $value !== 'None'))
                : array_filter($answers, fn ($value) => $value !== '');
        }

        return $answers;
    }

    /**
     * Data table fallback.
     */
    private function datatableFallback(DataTablePrompt $prompt): mixed
    {
        $choices = [];
        $keysByChoice = [];
        $position = 1;

        foreach ($prompt->rows as $key => $row) {
            $choice = $position . ': ' . $this->formatDataTableFallbackRow($prompt->headers, $row);

            $choices[$position] = $choice;
            $keysByChoice[$position] = $key;
            ++$position;
        }

        if ($choices === []) {
            return '';
        }

        $answer = $this->components->choice($prompt->label ?: 'Select an option', $choices);

        if (is_array($answer)) {
            return '';
        }

        return $keysByChoice[$answer] ?? '';
    }

    /**
     * Format a data table row for fallback selection.
     *
     * @param array<int, array<int, string>|string> $headers
     * @param array<int, string> $row
     */
    private function formatDataTableFallbackRow(array $headers, array $row): string
    {
        $cells = [];

        foreach ($row as $index => $value) {
            $header = $headers[$index] ?? '';
            $header = $this->formatDataTableFallbackText(is_array($header) ? implode(' ', $header) : $header);
            $value = $this->formatDataTableFallbackText($value);

            $cells[] = $header !== '' ? "{$header}: {$value}" : $value;
        }

        return implode(', ', $cells);
    }

    /**
     * Format data table fallback text.
     */
    private function formatDataTableFallbackText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /**
     * Get completion options for Symfony.
     *
     * @param array<string>|Closure(string): (array<string>|Collection<int, string>) $options
     * @return array<string>|callable(string): array<string>
     */
    private function completionOptions(array|Closure $options): array|callable
    {
        if (is_array($options)) {
            return $options;
        }

        return function (string $value) use ($options): array {
            $results = $options($value);

            return $results instanceof Collection ? $results->all() : $results;
        };
    }
}
