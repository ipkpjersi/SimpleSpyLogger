<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MessageIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageIngestController extends Controller
{
    public function __construct(private MessageIngestService $service) {}

    public function store(Request $request): JsonResponse
    {
        // Structural validation only. The message body has many optional
        // fields that are intentionally not enumerated here; do NOT use the
        // return value of validate() as the payload - it strips every key
        // without a rule, leaving message = {external_id} only. Pass the raw
        // input to the service, which maps fields defensively.
        $request->validate([
            'source' => 'required|string|max:32',
            'events' => 'required|array|min:1|max:500',
            'events.*.type' => 'required|in:create,update,delete',
            'events.*.captured_at' => 'nullable|string',
            'events.*.message' => 'required|array',
            'events.*.message.external_id' => 'required|string|max:64',
        ]);

        $stats = $this->service->ingestBatch($request->input('source'), $request->input('events'));

        return response()->json(['ok' => true, 'stats' => $stats]);
    }
}
