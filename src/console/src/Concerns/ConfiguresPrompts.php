<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Hypervel\Console\PromptValidationException;
use Hypervel\Prompts\ConfirmPrompt;
use Hypervel\Prompts\MultiSearchPrompt;
use Hypervel\Prompts\MultiSelectPrompt;
use Hypervel\Prompts\PasswordPrompt;
use Hypervel\Prompts\PausePrompt;
use Hypervel\Prompts\Prompt;
use Hypervel\Prompts\SearchPrompt;
use Hypervel\Prompts\SelectPrompt;
use Hypervel\Prompts\SuggestPrompt;
use Hypervel\Prompts\TextareaPrompt;
use Hypervel\Prompts\TextPrompt;
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

        Prompt::interactive(($input->isInteractive() && defined('STDIN') && stream_isatty(STDIN)) || $this->app->runningUnitTests());

        Prompt::validateUsing(fn (Prompt $prompt) => $this->validatePrompt($prompt->value(), $prompt->validate));

        Prompt::fallbackWhen(windows_os() || $this->app->runningUnitTests());

        TextPrompt::fallbackUsing(fn (TextPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->components->ask($prompt->label, $prompt->default ?: null) ?? '',
            $prompt->required,
            $prompt->validate
        ));

        TextareaPrompt::fallbackUsing(fn (TextareaPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->components->ask($prompt->label, $prompt->default ?: null, multiline: true) ?? '',
            $prompt->required,
            $prompt->validate
        ));

        PasswordPrompt::fallbackUsing(fn (PasswordPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->components->secret($prompt->label) ?? '',
            $prompt->required,
            $prompt->validate
        ));

        PausePrompt::fallbackUsing(fn (PausePrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt) {
                $this->components->ask($prompt->message);

                return $prompt->value();
            },
            $prompt->required,
            $prompt->validate
        ));

        ConfirmPrompt::fallbackUsing(fn (ConfirmPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->components->confirm($prompt->label, $prompt->default),
            $prompt->required,
            $prompt->validate
        ));

        SelectPrompt::fallbackUsing(fn (SelectPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->selectFallback($prompt->label, $prompt->options, $prompt->default),
            false,
            $prompt->validate
        ));

        MultiSelectPrompt::fallbackUsing(fn (MultiSelectPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->multiselectFallback($prompt->label, $prompt->options, $prompt->default, $prompt->required),
            $prompt->required,
            $prompt->validate
        ));

        SuggestPrompt::fallbackUsing(fn (SuggestPrompt $prompt) => $this->promptUntilValid(
            fn () => $this->components->askWithCompletion($prompt->label, $prompt->options, $prompt->default ?: null) ?? '',
            $prompt->required,
            $prompt->validate
        ));

        SearchPrompt::fallbackUsing(fn (SearchPrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt) {
                $query = $this->components->ask($prompt->label);

                $options = ($prompt->options)($query);

                return $this->selectFallback($prompt->label, $options);
            },
            false,
            $prompt->validate
        ));

        MultiSearchPrompt::fallbackUsing(fn (MultiSearchPrompt $prompt) => $this->promptUntilValid(
            function () use ($prompt) {
                $query = $this->components->ask($prompt->label);

                $options = ($prompt->options)($query);

                return $this->multiselectFallback($prompt->label, $options, required: $prompt->required);
            },
            $prompt->required,
            $prompt->validate
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

            if ($required && ($result === '' || $result === [] || $result === false)) {
                $this->components->error(is_string($required) ? $required : 'Required.');

                if ($this->app->runningUnitTests()) {
                    throw new PromptValidationException();
                }
                continue;
            }

            $error = is_callable($validate) ? $validate($result) : $this->validatePrompt($result, $validate);

            if (is_string($error) && strlen($error) > 0) {
                $this->components->error($error);

                if ($this->app->runningUnitTests()) {
                    throw new PromptValidationException();
                }
                continue;
            }

            return $result;
        }
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
        return $this->app['validator']->make(
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
     * Restore the prompts output.
     */
    protected function restorePrompts(): void
    {
        Prompt::setOutput($this->output);
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

        if ($required === false && ! $this->app->runningUnitTests()) {
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
}
