<?php

namespace Vigilance\Control;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * Reflects a registered artisan command's input definition into a structured
 * arguments/options schema that drives the dynamic "run command" form.
 */
class CommandReflector
{
    /**
     * @return array{
     *     arguments: array<int, array{name: string, required: bool, is_array: bool, default: mixed, description: string}>,
     *     options: array<int, array{name: string, shortcut: ?string, accept_value: bool, required: bool, is_array: bool, default: mixed, description: string}>
     * }
     */
    public function schema(string $name): array
    {
        $empty = ['arguments' => [], 'options' => []];

        try {
            $command = Artisan::all()[$name] ?? null;

            if ($command === null) {
                return $empty;
            }

            $definition = $command->getDefinition();
        } catch (\Throwable) {
            return $empty;
        }

        $arguments = [];

        foreach ($definition->getArguments() as $argument) {
            /** @var InputArgument $argument */
            $arguments[] = [
                'name' => $argument->getName(),
                'required' => $argument->isRequired(),
                'is_array' => $argument->isArray(),
                'default' => $argument->getDefault(),
                'description' => $argument->getDescription(),
            ];
        }

        $options = [];

        foreach ($definition->getOptions() as $option) {
            /** @var InputOption $option */
            $options[] = [
                'name' => $option->getName(),
                'shortcut' => $option->getShortcut(),
                'accept_value' => $option->acceptValue(),
                'required' => $option->isValueRequired(),
                'is_array' => $option->isArray(),
                'default' => $option->getDefault(),
                'description' => $option->getDescription(),
            ];
        }

        return [
            'arguments' => $arguments,
            'options' => $options,
        ];
    }
}
