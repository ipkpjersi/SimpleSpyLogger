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
        $data = $request->validate([
            'source' => 'required|string|max:32',
            'events' => 'required|array|min:1|max:500',
            'events.*.type' => 'required|in:create,update,delete',
            'events.*.captured_at' => 'nullable|string',
            'events.*.message' => 'required|array',
            'events.*.message.external_id' => 'required|string|max:64',
        ]);

        $stats = $this->service->ingestBatch($data['source'], $data['events']);

        return response()->json(['ok' => true, 'stats' => $stats]);
    }
}
