<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Error;
use Exception;
use Hypervel\Console\Command;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'invoke-serialized-closure')]
class InvokeSerializedClosureCommand extends Command
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    protected ?string $signature = 'invoke-serialized-closure {code? : The serialized closure}';

    protected string $description = 'Invoke the given serialized closure';

    protected bool $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            $this->output->write(json_encode([
                'successful' => true,
                'result' => base64_encode(serialize(
                    $this->hypervel->call($this->resolveSerializedClosure())
                )),
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            try {
                report($exception);
            } catch (Throwable) {
                // The response envelope remains the authoritative failure transport.
            }

            try {
                $parameters = $this->extractExceptionParameters($exception);

                // UTF-8 substitution would rewrite the class into a different,
                // nonexistent name, so degrade it like any unreconstructible type.
                if ($parameters !== null && ! mb_check_encoding($exception::class, 'UTF-8')) {
                    $parameters = null;
                }

                if ($parameters !== null) {
                    // Named arguments must survive JSON without changing types or nested state.
                    $encodedParameters = json_encode($parameters, self::JSON_FLAGS);

                    if (json_decode($encodedParameters, true, 512, JSON_THROW_ON_ERROR) !== $parameters) {
                        $parameters = null;
                    }
                }

                $exceptionClass = $exception::class;

                if ($parameters === null) {
                    $exceptionClass = RuntimeException::class;
                    $parameters = ['message' => $exception->getMessage()];
                }

                $payload = json_encode([
                    'successful' => false,
                    'exception' => $exceptionClass,
                    'message' => $exception->getMessage(),
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'parameters' => $parameters,
                ], self::JSON_FLAGS);
            } catch (Throwable) {
                $exceptionClass = addcslashes($exception::class, "\0..\37\177..\377");
                $message = "Concurrent task failed with [{$exceptionClass}]; its exception details could not be encoded.";

                $payload = json_encode([
                    'successful' => false,
                    'exception' => RuntimeException::class,
                    'message' => $message,
                    'parameters' => ['message' => $message],
                ], JSON_THROW_ON_ERROR);
            }

            $this->output->write($payload);
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the serialized closure from the command input or environment.
     */
    protected function resolveSerializedClosure(): callable
    {
        return match (true) {
            is_string($code = $this->argument('code')) && $code !== '' => unserialize($code),
            isset($_SERVER['HYPERVEL_INVOKABLE_CLOSURE']) => unserialize($this->decodeEnvironmentClosure(
                $_SERVER['HYPERVEL_INVOKABLE_CLOSURE']
            )),
            default => static fn () => null,
        };
    }

    /**
     * Decode the serialized closure stored in the environment.
     */
    protected function decodeEnvironmentClosure(mixed $value): string
    {
        if (! is_string($value)) {
            throw new RuntimeException('Missing serialized closure payload.');
        }

        $decodedValue = base64_decode($value, true);

        if ($decodedValue === false) {
            throw new RuntimeException('Unable to decode serialized closure payload.');
        }

        return $decodedValue;
    }

    /**
     * Extract reconstructible constructor parameters from the exception.
     *
     * Inaccessible optional state is replaced with declared defaults to preserve
     * the exception type, so messages derived from that state may differ from
     * the original exception sent through the best-effort reporter above.
     *
     * @return null|array<string, mixed>
     */
    protected function extractExceptionParameters(Throwable $exception): ?array
    {
        $reflection = new ReflectionClass($exception);
        $constructor = $reflection->getConstructor();

        if (
            $constructor === null
            || in_array($constructor->getDeclaringClass()->getName(), [Exception::class, Error::class], true)
        ) {
            return ['message' => $exception->getMessage()];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            // A same-named array property is one value, not the original variadic argument pack.
            if ($parameter->isVariadic()) {
                continue;
            }

            $name = $parameter->getName();

            if ($reflection->hasProperty($name)) {
                $property = $reflection->getProperty($name);

                if ($property->isPublic() && $property->isInitialized($exception)) {
                    $parameters[$name] = $property->getValue($exception);

                    continue;
                }
            }

            if ($parameter->isDefaultValueAvailable()) {
                $parameters[$name] = $parameter->getDefaultValue();

                continue;
            }

            if ($parameter->isOptional()) {
                continue;
            }

            return null;
        }

        return $parameters;
    }
}
