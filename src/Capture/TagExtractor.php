<?php

namespace Vigilance\Capture;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
        $tags = array_merge($tags, static::capabilitiesFor($command));

        if ($queue) {
            $tags[] = 'queue:'.$queue;
        }

        return array_values(array_unique(array_map(
            fn ($tag) => Str::limit((string) $tag, 80, ''),
            array_filter($tags)
        )));
    }

    /**
     * @return list<string>
     */
    protected static function capabilitiesFor(object $command): array
    {
        return static::forClass($command::class);
    }

    /**
     * Surface a job's queue capabilities as tags from its class name alone —
     * "unique" for ShouldBeUnique(-UntilProcessing), "encrypted" for
     * ShouldBeEncrypted. Class-based (not object-based) so it still works for
     * encrypted jobs, whose command object cannot be reconstructed at capture
     * time (the payload is opaque) — the one case that most needs the tag.
     *
     * @return list<string>
     */
    public static function forClass(?string $class): array
    {
        if ($class === null || ! class_exists($class)) {
            return [];
        }

        $tags = [];

        // ShouldBeUniqueUntilProcessing extends ShouldBeUnique, so this covers both.
        if (is_a($class, ShouldBeUnique::class, true)) {
            $tags[] = 'unique';
        }

        if (is_a($class, ShouldBeEncrypted::class, true)) {
            $tags[] = 'encrypted';
        }

        return $tags;
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
