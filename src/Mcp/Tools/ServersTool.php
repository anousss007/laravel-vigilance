<?php

namespace Vigilance\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Vigilance\Apm\Contracts\Storage;

#[Description('Current resource usage for each application server reporting to Vigilance: CPU percent, memory used/total (MB), disk usage, and whether the server is online (heartbeat within the last minute). Requires the vigilance:check heartbeat to be running.')]
#[IsReadOnly]
class ServersTool extends Tool
{
    public function handle(Request $request, Storage $storage): Response
    {
        $now = time();
        $servers = [];

        foreach ($storage->values('system') as $slug => $row) {
            $info = json_decode((string) $row->value, true);
            $info = is_array($info) ? $info : [];
            $updatedAt = (int) ($info['updated_at'] ?? $row->timestamp ?? 0);

            $servers[] = [
                'slug' => (string) $slug,
                'name' => (string) ($info['name'] ?? $slug),
                'cpu_percent' => (int) ($info['cpu'] ?? 0),
                'memory_used_mb' => (int) ($info['memory_used'] ?? 0),
                'memory_total_mb' => (int) ($info['memory_total'] ?? 0),
                'storage' => is_array($info['storage'] ?? null) ? $info['storage'] : [],
                'online' => $updatedAt > 0 && ($now - $updatedAt) <= 60,
                'updated_at' => $updatedAt > 0 ? date('c', $updatedAt) : null,
            ];
        }

        return $this->json([
            'count' => count($servers),
            'servers' => $servers,
        ]);
    }
}
