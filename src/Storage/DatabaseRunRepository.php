<?php

namespace Vigilance\Storage;

use Illuminate\Support\Carbon;
use Vigilance\Contracts\RunRepository;
use Vigilance\Data\RunData;
use Vigilance\Enums\RunStatus;
use Vigilance\Models\Run;
use Vigilance\Models\RunTag;

class DatabaseRunRepository implements RunRepository
{
    public function insert(RunData $data): int|string
    {
        $attributes = $data->toArray();
        $tags = $attributes['tags'] ?? [];

        $run = new Run;
        $run->forceFill($attributes);
        $run->save();

        $this->syncTags((int) $run->getKey(), is_array($tags) ? $tags : []);

        return $run->getKey();
    }

    public function updateByUuid(string $uuid, RunData $changes): bool
    {
        $run = $this->findOpenByUuid($uuid);

        if (! $run) {
            return false;
        }

        $run->forceFill($changes->toArray())->save();

        return true;
    }

    public function update(int|string $id, RunData $changes): bool
    {
        return Run::query()->whereKey($id)->update($changes->toArray()) > 0;
    }

    public function findOpenByUuid(string $uuid): ?Run
    {
        return Run::query()
            ->where('uuid', $uuid)
            ->whereIn('status', [
                RunStatus::Queued->value,
                RunStatus::Running->value,
                RunStatus::Released->value,
            ])
            ->latest('id')
            ->first();
    }

    public function delete(int|string $id): bool
    {
        return Run::query()->whereKey($id)->delete() > 0;
    }

    public function attachTags(int|string $id, array $tags): void
    {
        $this->syncTags((int) $id, $tags);
    }

    /** @param array<int, string> $tags */
    protected function syncTags(int $runId, array $tags): void
    {
        $tags = array_values(array_unique(array_filter($tags)));

        if ($tags === []) {
            return;
        }

        $now = Carbon::now();

        RunTag::query()->insert(array_map(fn (string $tag) => [
            'run_id' => $runId,
            'tag' => $tag,
            'created_at' => $now,
        ], $tags));
    }
}
