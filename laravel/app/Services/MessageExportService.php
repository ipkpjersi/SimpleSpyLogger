<?php

namespace App\Services;

use App\Models\Message;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;

class MessageExportService
{
    /**
     * Dump messages to a JSON file in the same shape the ingest API accepts,
     * so an export from one instance can be re-imported into another.
     *
     * One file per source. Pass null for $source to dump every source into
     * separate files alongside $path (path becomes a directory in that case).
     *
     * @return int number of message rows written
     */
    public function exportToFile(string $path, ?string $source = null, ?Carbon $since = null): int
    {
        if ($source === null) {
            return $this->exportAllSources($path, $since);
        }

        $payload = $this->buildPayload($source, $since);
        File::put($path, $this->encode($payload));
        return count($payload['events']);
    }

    private function exportAllSources(string $dir, ?Carbon $since): int
    {
        if (!is_dir($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $total = 0;
        $sources = Message::query()->distinct()->pluck('source');
        foreach ($sources as $src) {
            $payload = $this->buildPayload($src, $since);
            File::put(rtrim($dir, '/').'/'.$src.'.json', $this->encode($payload));
            $total += count($payload['events']);
        }
        return $total;
    }

    private function buildPayload(string $source, ?Carbon $since): array
    {
        $query = Message::where('source', $source);
        if ($since) {
            $query->where('captured_at', '>=', $since);
        }

        // Always emit `create` events. A message's deleted state travels in
        // its `deleted_at` field (handleCreate preserves it on import), so we
        // don't need a separate delete event to round-trip soft-deleted rows.
        $events = $query->orderBy('sent_at')
            ->get()
            ->map(fn (Message $m) => [
                'type' => 'create',
                'captured_at' => optional($m->captured_at)->toIso8601String(),
                'message' => $this->messageToEvent($m),
            ])
            ->all();

        return [
            'source' => $source,
            'exported_at' => now()->toIso8601String(),
            'events' => $events,
        ];
    }

    private function messageToEvent(Message $m): array
    {
        return [
            'external_id' => $m->external_id,
            'container_external_id' => $m->container_external_id,
            'container_name' => $m->container_name,
            'channel_external_id' => $m->channel_external_id,
            'channel_name' => $m->channel_name,
            'visibility' => $m->visibility,
            'author_external_id' => $m->author_external_id,
            'author_username' => $m->author_username,
            'author_display_name' => $m->author_display_name,
            'author_bot' => (bool) $m->author_bot,
            'content' => $m->content,
            'referenced_external_id' => $m->referenced_external_id,
            'sent_at' => optional($m->sent_at)->toIso8601String(),
            'source_edited_at' => optional($m->source_edited_at)->toIso8601String(),
            'deleted_at' => optional($m->deleted_at)->toIso8601String(),
            'payload' => $m->payload,
            // Derived permalink (Message::url accessor), emitted for downstream
            // and human use. Read-only: the importer ignores it on the way back
            // in, since url is computed from the other fields and not stored.
            'url' => $m->url,
        ];
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
