<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\MessageRevision;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageIngestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'source' => 'required|string|max:32',
            'events' => 'required|array|min:1|max:500',
            'events.*.type' => 'required|in:create,update,delete',
            'events.*.captured_at' => 'nullable|string',
            'events.*.message' => 'required|array',
            'events.*.message.external_id' => 'required|string|max:64',
        ]);

        $source = $data['source'];
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0];

        DB::transaction(function () use ($source, $data, &$stats) {
            foreach ($data['events'] as $event) {
                $type = $event['type'];
                $msg = $event['message'];
                $capturedAt = $this->parseTs($event['captured_at'] ?? null) ?? now();

                try {
                    if ($type === 'create') {
                        $this->handleCreate($source, $msg, $capturedAt, $stats);
                    } elseif ($type === 'update') {
                        $this->handleUpdate($source, $msg, $capturedAt, $stats);
                    } elseif ($type === 'delete') {
                        $this->handleDelete($source, $msg, $capturedAt, $stats);
                    }
                } catch (\Throwable $e) {
                    $stats['skipped']++;
                    Log::warning('SimpleSpyLogger ingest skip', [
                        'source' => $source,
                        'type' => $type,
                        'external_id' => $msg['external_id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        return response()->json(['ok' => true, 'stats' => $stats]);
    }

    private function handleCreate(string $source, array $msg, Carbon $capturedAt, array &$stats): void
    {
        $attrs = $this->mapMessage($source, $msg, $capturedAt);

        $existing = Message::where('source', $source)
            ->where('external_id', $attrs['external_id'])
            ->first();

        if ($existing) {
            $stats['skipped']++;
            return;
        }

        Message::create($attrs);
        $stats['created']++;
    }

    private function handleUpdate(string $source, array $msg, Carbon $capturedAt, array &$stats): void
    {
        $externalId = (string) $msg['external_id'];
        $existing = Message::where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        if (!$existing) {
            $attrs = $this->mapMessage($source, $msg, $capturedAt);
            Message::create($attrs);
            $stats['created']++;
            return;
        }

        MessageRevision::create([
            'message_id' => $existing->id,
            'content' => $existing->content,
            'payload' => $existing->payload,
            'source_edited_at' => $existing->source_edited_at,
            'captured_at' => $capturedAt,
            'created_at' => now(),
        ]);

        $update = [];
        if (array_key_exists('content', $msg)) {
            $update['content'] = $msg['content'];
        }
        if (array_key_exists('payload', $msg)) {
            $update['payload'] = $msg['payload'];
        }
        if (array_key_exists('source_edited_at', $msg)) {
            $update['source_edited_at'] = $this->parseTs($msg['source_edited_at']);
        }
        $existing->update($update);

        $stats['updated']++;
    }

    private function handleDelete(string $source, array $msg, Carbon $capturedAt, array &$stats): void
    {
        $externalId = (string) $msg['external_id'];
        $existing = Message::where('source', $source)
            ->where('external_id', $externalId)
            ->first();

        if (!$existing) {
            Message::create([
                'source' => $source,
                'external_id' => $externalId,
                'channel_external_id' => $msg['channel_external_id'] ?? null,
                'container_external_id' => $msg['container_external_id'] ?? null,
                'author_external_id' => '0',
                'author_username' => '[unknown]',
                'visibility' => $msg['visibility'] ?? 'public',
                'deleted_at' => $capturedAt,
                'captured_at' => $capturedAt,
            ]);
            $stats['deleted']++;
            return;
        }

        if (!$existing->deleted_at) {
            $existing->update(['deleted_at' => $capturedAt]);
        }
        $stats['deleted']++;
    }

    private function mapMessage(string $source, array $msg, Carbon $capturedAt): array
    {
        return [
            'source' => $source,
            'external_id' => (string) $msg['external_id'],
            'container_external_id' => $msg['container_external_id'] ?? null,
            'container_name' => $msg['container_name'] ?? null,
            'channel_external_id' => $msg['channel_external_id'] ?? null,
            'channel_name' => $msg['channel_name'] ?? null,
            'visibility' => $msg['visibility'] ?? 'public',
            'author_external_id' => (string) ($msg['author_external_id'] ?? '0'),
            'author_username' => (string) ($msg['author_username'] ?? '[unknown]'),
            'author_display_name' => $msg['author_display_name'] ?? null,
            'author_bot' => (bool) ($msg['author_bot'] ?? false),
            'content' => $msg['content'] ?? null,
            'referenced_external_id' => $msg['referenced_external_id'] ?? null,
            'sent_at' => $this->parseTs($msg['sent_at'] ?? null),
            'source_edited_at' => $this->parseTs($msg['source_edited_at'] ?? null),
            'captured_at' => $capturedAt,
            'payload' => $msg['payload'] ?? null,
        ];
    }

    private function parseTs($value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
