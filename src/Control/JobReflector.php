<?php

namespace Vigilance\Control;

use Illuminate\Database\Eloquent\Model;
use ReflectionEnum;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Reflects a job's constructor into an ordered list of parameter descriptors
 * that drive the dynamic dispatch form (and downstream type coercion). Eloquent
 * model- and backed-enum-typed parameters are flagged with extra metadata so
 * the UI can render the right control.
 */
class JobReflector
{
    /**
     * @return array<int, array{
     *     name: string,
     *     type: string|null,
     *     required: bool,
     *     has_default: bool,
     *     default: mixed,
     *     nullable: bool,
     *     is_model: bool,
     *     model_class: ?class-string,
     *     is_enum: bool,
     *     enum_class: ?class-string,
     *     enum_options: ?array<int|string, string>,
     *     builtin: ?string
     * }>
     */
    public function schema(string $jobClass): array
    {
        try {
            $reflection = new \ReflectionClass($jobClass);
            $constructor = $reflection->getConstructor();
        } catch (\Throwable) {
            return [];
        }

        if ($constructor === null) {
            return [];
        }

        $descriptors = [];

        foreach ($constructor->getParameters() as $parameter) {
            try {
                $descriptors[] = $this->describe($parameter);
            } catch (\Throwable) {
                continue;
            }
        }

        return $descriptors;
    }

    /**
     * @return array{
     *     name: string,
     *     type: string|null,
     *     required: bool,
     *     has_default: bool,
     *     default: mixed,
     *     nullable: bool,
     *     is_model: bool,
     *     model_class: ?class-string,
     *     is_enum: bool,
     *     enum_class: ?class-string,
     *     enum_options: ?array<int|string, string>,
     *     builtin: ?string
     * }
     */
    protected function describe(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $hasDefault = $parameter->isDefaultValueAvailable();

        $isModel = false;
        $modelClass = null;
        $isEnum = false;
        $enumClass = null;
        $enumOptions = null;
        $builtin = null;

        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                $builtin = $typeName;
            } elseif ($typeName !== null && class_exists($typeName)) {
                if (is_a($typeName, Model::class, true)) {
                    $isModel = true;
                    $modelClass = $typeName;
                } elseif (is_a($typeName, \BackedEnum::class, true)) {
                    $isEnum = true;
                    $enumClass = $typeName;
                    $enumOptions = $this->enumOptions($typeName);
                }
            }
        }

        return [
            'name' => $parameter->getName(),
            'type' => $typeName,
            'required' => ! $parameter->isOptional() && ! $parameter->allowsNull(),
            'has_default' => $hasDefault,
            'default' => $hasDefault ? $parameter->getDefaultValue() : null,
            'nullable' => $parameter->allowsNull(),
            'is_model' => $isModel,
            'model_class' => $modelClass,
            'is_enum' => $isEnum,
            'enum_class' => $enumClass,
            'enum_options' => $enumOptions,
            'builtin' => $builtin,
        ];
    }

    /**
     * Map a backed enum to a value => case-name list for the form's options.
     *
     * @param  class-string  $enumClass
     * @return array<int|string, string>
     */
    protected function enumOptions(string $enumClass): array
    {
        try {
            $options = [];

            foreach ((new ReflectionEnum($enumClass))->getCases() as $case) {
                /** @var \BackedEnum $instance */
                $instance = $case->getValue();
                $options[$instance->value] = $case->getName();
            }

            return $options;
        } catch (\Throwable) {
            return [];
        }
    }
}
