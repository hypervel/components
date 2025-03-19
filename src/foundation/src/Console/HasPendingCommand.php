<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console;

use Hyperf\Command\Annotation\Command as AnnotationCommand;
use Hyperf\Command\Parser;
use Hyperf\Di\Annotation\AnnotationCollector;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Exception\LogicException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

trait HasPendingCommand
{
    /**
     * @throws InvalidArgumentException
     * @throws \LogicException
     */
    protected function pendingCommand(SymfonyCommand $command): ?SymfonyCommand
    {
        /** @var null|AnnotationCommand $annotation */
        $annotation = AnnotationCollector::getClassAnnotation($command::class, AnnotationCommand::class) ?? null;

        if (! $annotation) {
            return CommandReplacer::replace($command);
        }

        if ($annotation->signature) {
            [$name, $arguments, $options] = Parser::parse($annotation->signature);
            if ($name) {
                $annotation->name = $name;
            }
            if ($arguments) {
                $annotation->arguments = array_merge($annotation->arguments, $arguments);
            }
            if ($options) {
                $annotation->options = array_merge($annotation->options, $options);
            }
        }

        if ($annotation->name) {
            $command->setName($annotation->name);
        }

        if ($annotation->arguments) {
            $annotation->arguments = array_map(static function ($argument): InputArgument {
                if ($argument instanceof InputArgument) {
                    return $argument;
                }

                if (is_array($argument)) {
                    return new InputArgument(...$argument);
                }

                throw new LogicException(sprintf('Invalid argument type: %s.', gettype($argument)));
            }, $annotation->arguments);

            $command->getDefinition()->addArguments($annotation->arguments);
        }

        if ($annotation->options) {
            $annotation->options = array_map(static function ($option): InputOption {
                if ($option instanceof InputOption) {
                    return $option;
                }

                if (is_array($option)) {
                    return new InputOption(...$option);
                }

                throw new LogicException(sprintf('Invalid option type: %s.', gettype($option)));
            }, $annotation->options);

            $command->getDefinition()->addOptions($annotation->options);
        }

        if ($annotation->description) {
            $command->setDescription($annotation->description);
        }

        if ($annotation->aliases) {
            $command->setAliases($annotation->aliases);
        }

        return CommandReplacer::replace($command);
    }
}
