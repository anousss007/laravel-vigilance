<?php

namespace Vigilance\Capture;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TagExtractor
{
    /**
     * Derive tags for a job command object: explicit tags() if defined, plus
     * auto-tags for any Eloquent model held on the job (Class:key).
     *
     * @return list<string>
     */
    public static function for(object $command, ?string $queue = null): array
    {
        $tags = [];

        if (method_exists($command, 'tags')) {
            try {
                $tags = array_merge($tags, (array) $command->tags());
            } catch (\Throwable) {
                // ignore a throwing tags() method
            }
        }

        $tags = array_merge($tags, static::modelsFor($command));

        if ($queue) {
            $tags[] = 'queue:'.$queue;
        }

        return array_values(array_unique(array_map(
            fn ($tag) => Str::limit((string) $tag, 80, ''),
            array_filter($tags)
        )));
    }

    /** @return list<string> */
    protected static function modelsFor(object $command): array
    {
        $tags = [];

        try {
            $properties = (new \ReflectionClass($command))->getProperties();
        } catch (\Throwable) {
            return [];
        }

        foreach ($properties as $property) {
            if (! $property->isInitialized($command)) {
                continue;
            }

            $value = $property->getValue($command);

            if ($value instanceof Model && $value->getKey() !== null) {
                $tags[] = get_class($value).':'.$value->getKey();
            } elseif ($value instanceof EloquentCollection) {
                foreach ($value as $model) {
                    if ($model->getKey() !== null) {
                        $tags[] = get_class($model).':'.$model->getKey();
                    }
                }
            }
        }

        return $tags;
    }
}
