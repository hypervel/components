<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hypervel\Console\Command;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'invoke-serialized-closure')]
class InvokeSerializedClosureCommand extends Command
{
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
                'result' => serialize($this->hypervel->call($this->resolveSerializedClosure())),
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            report($exception);

            $this->output->write(json_encode([
                'successful' => false,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'parameters' => $this->extractExceptionParameters($exception),
            ], JSON_THROW_ON_ERROR));
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
     * Extract public promoted constructor parameters from the exception.
     *
     * @return array<string, mixed>
     */
    protected function extractExceptionParameters(Throwable $exception): array
    {
        $reflection = new ReflectionClass($exception);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (! $reflection->hasProperty($name)) {
                $parameters[$name] = null;
                continue;
            }

            $property = $reflection->getProperty($name);

            $parameters[$name] = $property->isPublic()
                ? $property->getValue($exception)
                : null;
        }

        return $parameters;
    }
}
