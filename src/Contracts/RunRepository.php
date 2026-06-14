<?php

namespace Vigilance\Contracts;

use Vigilance\Data\RunData;

/**
 * Storage abstraction for recorded runs. The default implementation is
 * database-backed; the contract exists so a Redis (or other) driver can be
 * added without touching the capture layer.
 */
interface RunRepository
{
    /**
     * Insert a new run (a job has been queued, or a command/job/schedule has
     * started). Returns the stored run's primary key.
     */
    public function insert(RunData $data): int|string;

    /**
     * Merge changes into an existing run identified by its correlation uuid.
     * Returns true if a row was updated, false if no matching run was found
     * (e.g. the insert has not landed yet — the caller may retry/insert).
     */
    public function updateByUuid(string $uuid, RunData $changes): bool;

    /**
     * Update a run by primary key.
     */
    public function update(int|string $id, RunData $changes): bool;

    /**
     * Find the most recent open (queued/running) run for a uuid, if any.
     */
    public function findOpenByUuid(string $uuid): ?object;

    /**
     * Delete a run (used to drop sampled-out successful runs).
     */
    public function delete(int|string $id): bool;

    /**
     * Denormalize tags for a run into the run-tags side table.
     *
     * @param  array<int, string>  $tags
     */
    public function attachTags(int|string $id, array $tags): void;
}
