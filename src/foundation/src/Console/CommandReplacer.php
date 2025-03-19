<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Symfony\Component\Console\Command\Command;

class CommandReplacer
{
    protected static array $commands = [
        'start' => [
            'name' => 'serve',
            'description' => 'Start Hypervel servers',
        ],
        'server:watch' => null,
        'gen:amqp-consumer' => 'make:amqp-consumer',
        'gen:amqp-producer' => 'make:amqp-producer',
        'gen:aspect' => 'make:aspect',
        'gen:class' => 'make:class',
        'gen:command' => 'make:command',
        'gen:constant' => 'make:constant',
        'gen:controller' => 'make:controller',
        'gen:job' => null,
        'gen:kafka-consumer' => 'make:kafka-consumer',
        'gen:listener' => 'make:listener',
        'gen:middleware' => 'make:middleware',
        'gen:migration' => 'make:migration',
        'gen:model' => null,
        'gen:seeder' => null,
        'gen:nats-consumer' => 'make:nats-consumer',
        'gen:nsq-consumer' => 'make:nsq-consumer',
        'gen:observer' => null,
        'gen:process' => 'make:process',
        'gen:request' => null,
        'gen:resource' => 'make:resource',
        'gen:swagger' => 'make:swagger',
        'gen:migration-from-database' => 'make:migration-from-database',
        'gen:view-engine-cache' => 'view:cache',
        'gen:swagger-schema' => 'make:swagger-schema',
        'describe:routes' => [
            'name' => 'route:list',
            'description' => 'List all registered routes',
        ],
        'describe:aspects' => 'aspect:list',
        'describe:listeners' => null,
    ];

    public static function replace(Command $command, bool $remainAlias = true): ?Command
    {
        $commandName = $command->getName();
        if (! array_key_exists($commandName, static::$commands)) {
            return $command;
        }

        if (! $replace = static::$commands[$commandName] ?? null) {
            return null;
        }

        $command->setName($replace['name'] ?? $replace);
        if ($remainAlias) {
            $command->setAliases([$commandName]);
        }

        if ($description = $replace['description'] ?? null) {
            $command->setDescription($description);
        }

        return $command;
    }
}
