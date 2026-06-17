<?php

namespace Vigilance\Mcp\Tools;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Description('Job batches (Laravel bus batches): id, name, total/pending/processed/failed job counts, progress percent, and created/finished/cancelled times. Requires the job_batches table; reports supported=false if it is not configured.')]
#[IsReadOnly]
class BatchesTool extends Tool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max batches to return (capped by the server).'),
        ];
    }

    public function handle(Request $request, BatchRepository $batches): Response
    {
        $limit = $this->resolveLimit($request->integer('limit'));

        try {
            $found = $batches->get($limit, null);
        } catch (Throwable) {
            return $this->json([
                'supported' => false,
                'note' => 'The job_batches table is not configured in this application.',
                'batches' => [],
            ]);
        }

        $rows = array_map(fn (Batch $b): array => [
            'id' => $b->id,
            'name' => $b->name,
            'total_jobs' => $b->totalJobs,
            'pending_jobs' => $b->pendingJobs,
            'processed_jobs' => $b->processedJobs(),
            'failed_jobs' => $b->failedJobs,
            'progress' => $b->progress(),
            'finished' => $b->finished(),
            'cancelled' => $b->cancelled(),
            'created_at' => $this->date($b->createdAt),
            'finished_at' => $this->date($b->finishedAt),
            'cancelled_at' => $this->date($b->cancelledAt),
        ], $found);

        return $this->json([
            'supported' => true,
            'count' => count($rows),
            'batches' => $rows,
        ]);
    }
}
